/******************************************************************************
 * warpTunnel
 * A warp jump: the same winding tube as the EQ warp, but its walls are no grid — streaks of light
 * stream past, dense stars shoot by, and the far end dissolves into a bright exit. Fast even in
 * silence, because a warp tunnel at a crawl is a corridor.
 *
 * WHAT DRIVES WHAT: the bass and the beat add thrust on top of a high cruise; the level brightens
 * the streaks and the exit; the treble lights the stars; the mids hurry the tube's roll.
 *
 * THE STREAKS ARE NOISE STRETCHED ALONG THE TUBE — many cells round it, few along it — and the noise
 * is PERIODIC both ways: round the tube, so there is no seam where the angle wraps, and along it,
 * so the travel can be wrapped on the CPU (precision) without the pattern jumping. Every fbm octave
 * doubles both the frequency and the period exactly, which keeps each octave periodic too.
 *****************************************************************************/
import { fragmentShader, glslFloat } from "../Shader/glsl";
import { ease, wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";
import { PATH_FAR, STAR_PERIOD, STAR_ROWS, TUBE_UNIFORMS, setTube, windingPath } from "../WindingTube/windingTube";
import { TUBE_GLSL, TUBE_UNIFORMS_GLSL } from "../WindingTube/windingTubeGlsl";

/** Cruising speed in silence, in world units a second — already a rush. */
const CRUISE = 2.4;

/** How much the bass adds to the speed at full level. */
const BASS_THRUST = 3;

/** How much a beat adds, decaying with the beat envelope. */
const BEAT_THRUST = 1.2;

/** How quickly the speed follows its target, in seconds. */
const SPEED_LAG = 0.2;

/** Streak cells round the tube — and the noise's period round it, so the angle's wrap is seamless. */
const AROUND = 28;

/** Streak cells per world unit along the tube: few, which is what stretches the noise into streaks. */
const ALONG = 0.5;

/** The noise's period along the tube, in cells. The travel wraps at ALONG_PERIOD / ALONG units. */
const ALONG_PERIOD = 64;

/** Where the exit's light is centred: deep enough to sit at the visible end of a bend. */
const GLOW_DEPTH = PATH_FAR - 2;

/** The picture, per pixel. */
const FRAGMENT = fragmentShader(
    // language=GLSL
    `${TUBE_UNIFORMS_GLSL}
uniform float uTravel;
uniform float uStarTravel;
uniform float uSpin;
uniform float uSpeed;
`,
    // language=GLSL
    `${TUBE_GLSL}
const vec2 STREAK_PERIOD = vec2(${glslFloat(AROUND)}, ${glslFloat(ALONG_PERIOD)});

// Value noise that repeats every period cells in each direction — see the file note.
float periodicNoise(vec2 p, vec2 period) {
    vec2 cell = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash12(mod(cell, period)), hash12(mod(cell + vec2(1.0, 0.0), period)), u.x),
        mix(hash12(mod(cell + vec2(0.0, 1.0), period)), hash12(mod(cell + vec2(1.0, 1.0), period)), u.x),
        u.y
    );
}

// Four octaves of periodic noise; each doubles frequency AND period, so each stays periodic.
float streaks(vec2 p, vec2 period) {
    float total = 0.0;
    float amplitude = 0.5;
    for (int octave = 0; octave < 4; octave++) {
        total += amplitude * periodicNoise(p, period);
        p *= 2.0;
        period *= 2.0;
        amplitude *= 0.5;
    }
    return total;
}

void main() {
    vec2 p = centred();
    mat2 roll = rotation(uSpin);

    vec4 wall = hit(p, TUBE);
    vec2 q = roll * wall.xy;
    float around = atan(q.x, -q.y) / TAU + 0.5;
    float depth = wall.z;
    float z = depth + uTravel;

    float streak = streaks(vec2(around * STREAK_PERIOD.x, z * ${glslFloat(ALONG)}), STREAK_PERIOD);
    // Contrast: most of the wall is dark, the brightest threads of the noise are the light.
    float light = pow(streak, 3.0) * 2.4;
    // The nearest wall is a blur too close to read, so the eye goes to the middle of the tunnel.
    float nearFade = smoothstep(0.3, 1.4, depth);

    vec3 colour = uSpace;
    colour += mix(uMid, uHigh, streak) * light * (0.35 + 0.65 * uLevel) * nearFade;
    // The far end dissolves into the exit — and so does whatever lies past the sampled tube.
    float exit = max(smoothstep(2.5, ${glslFloat(PATH_FAR - 0.5)}, depth), 1.0 - wall.w);
    colour = mix(colour, uGlow * 0.85, exit);
    colour += uGlow * exp(-length(p - uGlowCentre) * 4.0) * (0.45 + 0.55 * uLevel);
    colour += starStreaks(p, roll, uStarTravel, uSpeed, 0.22, 0.6 + 1.2 * uTreble);

    colour *= mix(0.65, 1.0, 1.0 - smoothstep(0.45, 1.25, length(p)));
    outColor = vec4(finish(colour), 1.0);
}
`
);

/** One party's jump: position, speed, roll and path. */
function start(gl: WebGL2RenderingContext): ShaderState {
    let travel = 0;
    let speed = CRUISE;
    let spin = 0;
    const path = windingPath();

    return {
        /** Fly one frame on and set the uniforms. */
        frame({ uniforms, frame, step }) {
            speed = ease(speed, CRUISE + frame.bass * BASS_THRUST + frame.beat * BEAT_THRUST, step, SPEED_LAG);
            travel += speed * step;
            spin += step * (0.05 + frame.mid * 0.3);

            gl.uniform1f(uniforms.uTravel ?? null, wrap(travel, ALONG_PERIOD / ALONG));
            gl.uniform1f(uniforms.uStarTravel ?? null, wrap(travel * 1.6, STAR_PERIOD / STAR_ROWS));
            gl.uniform1f(uniforms.uSpin ?? null, wrap(spin, Math.PI * 2));
            gl.uniform1f(uniforms.uSpeed ?? null, speed);
            setTube(gl, uniforms, path, travel, GLOW_DEPTH);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "warpTunnel",
    fragment: FRAGMENT,
    uniforms: ["uTravel", "uStarTravel", "uSpin", "uSpeed", ...TUBE_UNIFORMS],
    start
});
