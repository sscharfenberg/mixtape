/******************************************************************************
 * shaders
 * The EQ warp's fragment shader: the spectrum tunnel, the star streaks inside it and the glow at
 * the vanishing point, all drawn per pixel over glsl.ts's full-screen triangle.
 *
 * NO GEOMETRY. Every pixel works out which piece of wall it is looking at from its own position on
 * the screen — WindingTube's `hit` walks the tube's rings to the one it sees — so there are no
 * meshes, no buffers and nothing to upload but one small texture a frame.
 *
 * WHAT DRIVES WHAT, so the picture means something rather than merely moving:
 *   - the WALLS are the spectrum's recent history (see tunnelMath) — pitch round the tube, time
 *     along it, each cell lit and coloured by its band's level through the same magenta → violet
 *     → blue ramp as the bars on the Now Playing page;
 *   - the BASS sets the flight speed (in the renderer), so a drop is a lurch forward;
 *   - the TREBLE lights the stars, which streak longer the faster you fly;
 *   - the LEVEL and the BEAT feed the glow at the centre, and the beat lifts the whole picture
 *     through `finish`, bounded so it is never a flash.
 *
 * The geometry constants are interpolated from tunnelMath, so the renderer's bookkeeping and the
 * shader's sampling cannot disagree about how long a row is.
 *****************************************************************************/
import { fragmentShader, glslFloat } from "../Shader/glsl";
import { TUBE_GLSL, TUBE_UNIFORMS_GLSL } from "../WindingTube/windingTubeGlsl";
import { HISTORY_LENGTH, RING_LENGTH, SEGMENTS, SPAWN_DEPTH } from "./tunnelMath";

/** The whole picture, per pixel. Uniforms are documented where the renderer sets them. */
export const FRAGMENT_SHADER = fragmentShader(
    // language=GLSL
    `${TUBE_UNIFORMS_GLSL}
uniform float uTravel;
uniform float uStarTravel;
uniform float uSpin;
uniform float uSpeed;
uniform sampler2D uHistory;
`,
    // language=GLSL
    `${TUBE_GLSL}
const float SPAWN = ${glslFloat(SPAWN_DEPTH)};
const float HISTORY = ${glslFloat(HISTORY_LENGTH)};
const float RING = ${glslFloat(RING_LENGTH)};
const float SEGMENTS = ${glslFloat(SEGMENTS)};

void main() {
    vec2 p = centred();
    float screenRadius = length(p);
    mat2 roll = rotation(uSpin);

    vec4 wall = hit(p, TUBE);
    vec2 q = roll * wall.xy;
    float a = atan(q.x, -q.y); // 0 at the floor, +-PI at the roof
    float angleWidth = abs(folded(dFdx(a))) + abs(folded(dFdy(a)));

    // ---- the walls -------------------------------------------------------------------------
    float depth = wall.z;
    float z = depth + uTravel;
    float around = a / TAU * SEGMENTS;
    float along = z / RING;
    vec2 cellId = floor(vec2(around, along));
    vec2 cell = fract(vec2(around, along));
    // Cell units per pixel, in both directions: what turns "distance to the cell's edge" into
    // pixels, so lines stay one pixel wide at every depth.
    vec2 perPixel = max(vec2(angleWidth / TAU * SEGMENTS, fwidth(along)), vec2(1e-5));
    vec2 inset = min(cell, 1.0 - cell);
    vec2 edgePixels = inset / perPixel;
    float edge = min(edgePixels.x, edgePixels.y);

    // Mirrored, so both sides read the same EQ: bass on the floor, treble on the roof. Sampled
    // at the cell's centre, so a cell is one level — an LED, not a smear.
    float band = abs(cellId.x + 0.5) / (SEGMENTS * 0.5);
    float level = texture(uHistory, vec2(band, (cellId.y + 0.5) * RING / HISTORY)).r;
    // Only the peaks light: a wall of evenly glowing panels is a room, not space. Squared after
    // the threshold, so a loud band is a light and a middling one a glimmer.
    float lit = smoothstep(0.18, 1.0, level);
    lit *= lit;

    // Distance: everything dims with depth, and nothing is drawn past the spawn point, where the
    // buffer holds a previous lap rather than the music.
    float fog = exp(-depth * 0.22);
    float spawn = (1.0 - smoothstep(SPAWN - 1.5, SPAWN, depth)) * wall.w;
    // Cells under a couple of pixels would shimmer, so far away they melt into an even haze.
    float detail = 1.0 - smoothstep(0.3, 0.7, max(perPixel.x, perPixel.y));
    float line = (1.0 - smoothstep(0.6, 1.6, edge)) * detail;
    // A panel inside its cell, the gap a fixed share of the cell rather than of the screen — so
    // it opens up as the panel rushes closer, which is what makes the wall read as a surface in
    // depth instead of a flat mosaic. Never under a pixel and a half, or far panels would merge.
    // Thinner ALONG the tube than around it: perspective stretches a near cell to several times
    // its width in depth, and an even share there draws a heavy black hoop between every ring.
    vec2 gap = vec2(0.1, 0.03);
    vec2 panel = smoothstep(gap + perPixel, gap + perPixel * 2.5, inset);
    float fill = mix(0.55, panel.x * panel.y, detail);

    vec3 colour = uSpace;
    colour += uGrid * line * (0.6 + 0.4 * uLevel) * fog * spawn;
    colour += ramp(level) * fill * lit * 1.5 * mix(fog, 1.0, 0.3) * spawn;

    // ---- the stars and the light at the end -------------------------------------------------
    colour += starStreaks(p, roll, uStarTravel, uSpeed, 0.08, 0.35 + 1.1 * uTreble);
    colour += uGlow * exp(-length(p - uGlowCentre) * 6.0) * (0.25 + 0.55 * uLevel + 0.3 * uBeat);

    colour *= mix(0.7, 1.0, 1.0 - smoothstep(0.45, 1.25, screenRadius));
    outColor = vec4(finish(colour), 1.0);
}
`
);
