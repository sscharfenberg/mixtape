/******************************************************************************
 * shaders
 * The warp tunnel's GLSL: one triangle that covers the screen, and a fragment shader that draws
 * the whole picture per pixel — the spectrum tunnel, the star streaks inside it and the glow at
 * the vanishing point.
 *
 * NO GEOMETRY. Every pixel works out which piece of wall it is looking at from its own angle and
 * distance from the centre (a "polar tunnel": depth is inversely proportional to radius), so
 * there are no meshes, no buffers and nothing to upload but one small texture a frame.
 *
 * THE TUBE WINDS (tunnelMath → CURVE), which a plain polar tunnel cannot: there, depth follows from
 * the radius in one step. Here each ring's centre is offset by where the path has wandered by that
 * depth. The renderer samples the path once a frame (`uPath`, forty rings from near to far) and
 * `hit` walks those rings outwards to the first one the pixel lies outside of — the nearest wall,
 * which is the one you see where a bend puts two walls on one line of sight.
 *
 * WHAT DRIVES WHAT, so the picture means something rather than merely moving:
 *   - the WALLS are the spectrum's recent history (see tunnelMath) — pitch round the tube, time
 *     along it, each cell lit and coloured by its band's level through the same magenta → violet
 *     → blue ramp as the bars on the Now Playing page;
 *   - the BASS sets the flight speed (in the renderer), so a drop is a lurch forward;
 *   - the TREBLE lights the stars, which streak longer the faster you fly;
 *   - the LEVEL and the BEAT feed the glow at the centre, and the beat lifts the whole picture by
 *     at most 12% — enough to feel, and bounded so a beat is never a flash (WCAG 2.3.1; the beat
 *     itself is rate-limited in audioFrame).
 *
 * The geometry constants are interpolated from tunnelMath, so the renderer's bookkeeping and the
 * shader's sampling cannot disagree about how long a row is.
 *****************************************************************************/
import {
    HISTORY_LENGTH,
    PATH_SAMPLES,
    RING_LENGTH,
    SEGMENTS,
    SPAWN_DEPTH,
    STAR_PERIOD,
    STAR_ROWS,
    STAR_TUBE,
    TUBE,
    pathDepth
} from "./tunnelMath";

/** A number as a GLSL float literal — `7` is an int in GLSL and would not compile as a float. */
const float = (value: number): string => (Number.isInteger(value) ? `${value}.0` : String(value));

/** One over each sampled ring's depth, as a GLSL array literal — the ring's size on screen per unit of tube. */
const inverseDepths = (): string =>
    `float[${PATH_SAMPLES}](${Array.from({ length: PATH_SAMPLES }, (_, index) => float(1 / pathDepth(index))).join(", ")})`;

/**
 * One triangle, three times the screen's size, from `gl_VertexID` alone — no vertex buffer and no
 * attribute at all, which WebGL2 allows against its default vertex array.
 */
export const VERTEX_SHADER = `#version 300 es
void main() {
    vec2 corner = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
    gl_Position = vec4(corner * 2.0 - 1.0, 0.0, 1.0);
}
`;

/** The whole picture, per pixel. Uniforms are documented where the renderer sets them. */
export const FRAGMENT_SHADER = `#version 300 es
precision highp float;

uniform vec2 uResolution;
uniform float uTravel;
uniform float uStarTravel;
uniform float uSpin;
uniform vec2 uPath[${PATH_SAMPLES}];
uniform vec2 uGlowCentre;
uniform float uSpeed;
uniform float uTreble;
uniform float uLevel;
uniform float uBeat;
uniform sampler2D uHistory;
uniform vec3 uSpace;
uniform vec3 uGrid;
uniform vec3 uLow;
uniform vec3 uMid;
uniform vec3 uHigh;
uniform vec3 uGlow;

out vec4 outColor;

const float TAU = 6.28318530718;
const float TUBE = ${float(TUBE)};
const float SPAWN = ${float(SPAWN_DEPTH)};
const float HISTORY = ${float(HISTORY_LENGTH)};
const float RING = ${float(RING_LENGTH)};
const float SEGMENTS = ${float(SEGMENTS)};
const float STAR_TUBE = ${float(STAR_TUBE)};
const float STAR_ROWS = ${float(STAR_ROWS)};
const float STAR_PERIOD = ${float(STAR_PERIOD)};
const int PATH_SAMPLES = ${PATH_SAMPLES};
const float INVERSE_DEPTHS[PATH_SAMPLES] = ${inverseDepths()};

// A cheap, sin-free hash: sin() of large arguments loses precision on mobile GPUs and the
// pattern visibly bands.
float hash(vec2 p) {
    p = fract(p * vec2(123.34, 456.21));
    p += dot(p, p + 45.32);
    return fract(p.x * p.y);
}

// The level ramp, quiet to loud — the bars' three stops.
vec3 ramp(float v) {
    return v < 0.5 ? mix(uLow, uMid, v * 2.0) : mix(uMid, uHigh, v * 2.0 - 1.0);
}

// An angle difference folded back into (-PI, PI]: across the seam at the roof, atan jumps a full
// turn between two neighbouring pixels, and an unfolded derivative there draws a hairline.
float folded(float d) {
    return d - TAU * floor(d / TAU + 0.5);
}

// Which ring of a winding tube of radius tube the screen point p sees: the point's position in that
// ring's own cross-section (xy), the ring's depth (z), and whether it sees a wall at all (w).
//
// Each sampled ring is a circle on the screen — centred where the path puts it (uPath), radius
// tube over its depth. Nearer rings are bigger, so p starts inside them; the first ring p lies
// OUTSIDE of is where its line of sight leaves the tube, i.e. the nearest wall. Walking from near
// to far is what makes that the visible one where a bend lines two walls up. Between the last ring
// that contains p and the first that does not, the circle is interpolated and the crossing found
// by two false-position steps; interpolating the RADIUS linearly is exact for a straight tube,
// since a ring's size goes as one over its depth. A line of sight that never leaves the sampled
// tube sees past its end, into the dark the spawn fade leaves there (w = 0).
vec4 hit(vec2 p, float tube) {
    vec2 nearCentre = uPath[0];
    float nearRadius = tube * INVERSE_DEPTHS[0];
    float nearGap = length(p - nearCentre) - nearRadius;
    // Outside even the nearest ring: only an extreme corner of a very wide screen, on the stars'
    // narrow tube. It sees the nearest wall.
    if (nearGap >= 0.0) return vec4(p - nearCentre, tube / nearRadius, 1.0);

    for (int index = 1; index < PATH_SAMPLES; index++) {
        vec2 farCentre = uPath[index];
        float farRadius = tube * INVERSE_DEPTHS[index];
        float farGap = length(p - farCentre) - farRadius;
        if (farGap >= 0.0) {
            float low = 0.0;
            float high = 1.0;
            float lowGap = nearGap;
            float highGap = farGap;
            float t = 0.0;
            for (int refine = 0; refine < 2; refine++) {
                t = mix(low, high, lowGap / (lowGap - highGap));
                float gap = length(p - mix(nearCentre, farCentre, t)) - mix(nearRadius, farRadius, t);
                if (gap < 0.0) { low = t; lowGap = gap; } else { high = t; highGap = gap; }
            }
            t = mix(low, high, lowGap / (lowGap - highGap));
            return vec4(p - mix(nearCentre, farCentre, t), tube / mix(nearRadius, farRadius, t), 1.0);
        }
        nearCentre = farCentre;
        nearRadius = farRadius;
        nearGap = farGap;
    }
    return vec4(p - nearCentre, tube / nearRadius, 0.0);
}

void main() {
    float unit = min(uResolution.x, uResolution.y);
    vec2 p = (gl_FragCoord.xy - 0.5 * uResolution) / unit;
    float screenRadius = length(p);
    float c = cos(uSpin);
    float s = sin(uSpin);
    mat2 roll = mat2(c, s, -s, c);

    vec4 wall = hit(p, TUBE);
    vec2 q = roll * wall.xy;
    float r = max(length(q), 1e-4);
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

    // ---- the stars -------------------------------------------------------------------------
    // Their own, narrower tube along the same path — so they bend with the walls.
    vec4 inner = hit(p, STAR_TUBE);
    vec2 starPoint = roll * inner.xy;
    float starDepth = inner.z;
    vec2 starCell = vec2(atan(starPoint.x, -starPoint.y) / TAU * 96.0, (starDepth + uStarTravel) * STAR_ROWS);
    vec2 starId = vec2(floor(starCell.x), mod(floor(starCell.y), STAR_PERIOD));
    float pick = hash(starId);
    if (pick > 0.92) {
        vec2 offset = vec2(hash(starId + 7.1), hash(starId + 3.3)) - 0.5;
        vec2 d = fract(starCell) - 0.5 - offset * 0.6;
        float stretch = 1.0 + uSpeed * 1.5;
        float star = exp(-(d.x * d.x) / 0.004 - (d.y * d.y) / (0.004 * stretch * stretch));
        // Gone well before the walls' spawn point, where the far end of a winding tube is dark.
        float fade = (1.0 - smoothstep(3.0, 6.0, starDepth)) * smoothstep(0.04, 0.2, length(starPoint)) * inner.w;
        colour += mix(uHigh, uGlow, offset.x + 0.5) * star * (pick - 0.92) / 0.08 * (0.35 + 1.1 * uTreble) * fade;
    }

    // ---- the light at the end --------------------------------------------------------------
    // Centred where the path has taken the last rings the eye can see (uGlowCentre: they fade from
    // SPAWN - 1.5), so the light stays at the end of the visible tunnel as it winds — centred on the
    // path's far end instead, a sharp bend put it on a wall.
    float glow = length(p - uGlowCentre);
    colour += uGlow * exp(-glow * 6.0) * (0.25 + 0.55 * uLevel + 0.3 * uBeat);

    // A beat lifts everything a little — bounded, see the file note.
    colour *= 1.0 + 0.12 * uBeat;
    colour *= mix(0.7, 1.0, 1.0 - smoothstep(0.45, 1.25, screenRadius));

    outColor = vec4(min(colour, vec3(1.0)), 1.0);
}
`;
