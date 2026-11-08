/******************************************************************************
 * nebula
 * Deep space with a nebula drifting across it: thin, glowing gas over a field of stars, cut by
 * dark lanes of dust, slowly turning. Each frequency range lights a colour of the gas.
 *
 * SPACE FIRST, GAS SECOND. Most of the screen stays dark, and the gas is ADDED as light over the
 * star field, thin enough to see stars through, rather than painted over it as an opaque layer —
 * that is the difference between a nebula and a coloured liquid. A threshold on the cloud's density
 * decides where there is gas at all; a fine-grained second noise breaks it into wisps; a third cuts
 * dark dust lanes through it.
 *
 * THE CLOUD IS WARPED ONCE, GENTLY: fbm sampled at a point another fbm has pushed a little. Warping
 * deeper folds the shapes into each other until they read as stirred goo, which is the opposite of
 * the ragged, filamentary edge a real nebula has.
 *
 * THE STARS ARE THREE LAYERS of hashed points — a few large ones with a halo, more medium ones, a
 * dust of faint ones — each twinkling at its own rate, brighter with the treble.
 *
 * WHAT DRIVES WHAT: the bass lights the magenta of the gas, the mids its violet, the treble its blue
 * and the stars; the level brightens the whole and hurries the drift.
 *
 * COST: five fbm evaluations a pixel plus three star layers — one of the heavier pictures, which
 * the host's resolution budget keeps affordable.
 *****************************************************************************/
import { fragmentShader } from "../Shader/glsl";
import { wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";

/**
 * How far the drift runs before it wraps. The noise is not periodic, so the wrap is a jump — but
 * at the drift's speed it comes round after hours, and the cloud has long since changed shape.
 */
const DRIFT_PERIOD = 1000;

/** The picture, per pixel. */
const FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform float uDrift;
uniform float uTurn;
`,
    // language=GLSL
    `
// One layer of stars: cells of 1/scale of the screen's short side, a share (density) of them
// holding a star of the given size (in cells) at a hashed position, twinkling.
vec3 starLayer(vec2 p, float scale, float density, float size, float halo, float seed) {
    vec2 grid = p * scale + seed;
    vec2 cell = floor(grid);
    float pick = hash12(cell);
    if (pick < 1.0 - density) return vec3(0.0);

    vec2 centre = 0.2 + 0.6 * hash22(cell + seed);
    float d = length(fract(grid) - centre);
    float twinkle = 0.75 + 0.25 * sin(uTime * (0.8 + 2.5 * hash12(cell + 3.7)) + pick * 50.0);
    // A star is drawn only inside its own cell, so the halo must reach nothing at the cell's edge:
    // cut off there instead, it ends in four straight lines — a faint square round every large
    // star, plain on a screen that lifts near-black (a TV) though lost on a laptop's.
    vec2 inCell = fract(grid);
    float edge = min(min(inCell.x, 1.0 - inCell.x), min(inCell.y, 1.0 - inCell.y));
    float light = exp(-d * d / (size * size)) + halo * exp(-d * 7.0) * smoothstep(0.0, 0.2, edge);
    vec3 tint = mix(uGlow, uHigh, hash12(cell + 1.3));
    return tint * light * twinkle * (0.65 + 0.6 * uTreble);
}

void main() {
    vec2 screen = centred();
    vec2 p = rotation(uTurn) * screen * 1.4;

    // ---- the stars -------------------------------------------------------------------------
    vec3 stars = starLayer(screen, 18.0, 0.18, 0.06, 0.12, 0.0)
        + starLayer(screen, 55.0, 0.10, 0.07, 0.0, 17.0)
        + starLayer(screen, 140.0, 0.12, 0.09, 0.0, 41.0) * 0.6;

    // ---- the gas ---------------------------------------------------------------------------
    vec2 q = vec2(fbm(p + vec2(0.0, uDrift * 0.6)), fbm(p + vec2(5.2, 1.3) - uDrift * 0.4));
    // Biased towards the middle of the screen, so wherever the drift has wandered there is a
    // nebula to look at rather than an empty patch of sky.
    float f = fbm(p + 1.6 * q + vec2(uDrift * 0.2, 0.0)) + 0.3 * (1.0 - smoothstep(0.0, 1.0, length(screen)));
    float wisps = fbm(p * 4.0 + q * 2.0);
    float density = smoothstep(0.42, 0.9, f) * (0.45 + 0.7 * wisps);
    // Dark lanes of dust across the gas.
    float dust = smoothstep(0.52, 0.78, fbm(p * 2.2 - q * 1.5 + 11.0));
    density *= 1.0 - 0.8 * dust;

    // Its colour drifts across the cloud with the warp, each colour lit by its own band.
    float violet = clamp(q.x * 1.6 - 0.3, 0.0, 1.0);
    float blue = smoothstep(0.5, 0.85, q.y);
    vec3 gas = mix(uLow * (0.6 + 0.7 * uBass), uMid * (0.6 + 0.7 * uMids), violet);
    gas = mix(gas, uHigh * (0.6 + 0.8 * uTreble), blue);

    vec3 colour = uSpace;
    // Stars show through thin gas and fade behind thick gas and dust.
    colour += stars * (1.0 - 0.6 * density) * (1.0 - 0.5 * dust);
    colour += gas * density * (0.7 + 0.4 * uLevel);
    // The brightest knots of gas glow.
    colour += uGlow * pow(density, 3.0) * 0.3;

    colour *= mix(0.75, 1.0, 1.0 - smoothstep(0.5, 1.3, length(screen)));
    outColor = vec4(finish(colour), 1.0);
}
`
);

/** One party's nebula: how far it has drifted and turned. */
function start(gl: WebGL2RenderingContext): ShaderState {
    let drift = Math.random() * 100;
    let turn = 0;

    return {
        /** Drift and turn on, faster when the music is louder, and set the uniforms. */
        frame({ uniforms, frame, step }) {
            drift = wrap(drift + step * (0.03 + frame.level * 0.1), DRIFT_PERIOD);
            turn = wrap(turn + step * (0.008 + frame.mid * 0.02), Math.PI * 2);

            gl.uniform1f(uniforms.uDrift ?? null, drift);
            gl.uniform1f(uniforms.uTurn ?? null, turn);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "nebula",
    fragment: FRAGMENT,
    uniforms: ["uDrift", "uTurn"],
    start
});
