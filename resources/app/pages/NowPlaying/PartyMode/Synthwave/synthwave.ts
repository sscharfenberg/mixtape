/******************************************************************************
 * synthwave
 * The app's own look as a place: a neon grid floor running to the horizon, a striped sun sinking
 * behind a ridge of mountains, and stars over a violet haze.
 *
 * WHAT DRIVES WHAT:
 *   - the MOUNTAINS are the spectrum — the ridge's height along the horizon is the bands' level,
 *     mirrored, with the bass at the screen's edges and the treble in the middle, behind the sun;
 *   - the BASS and the BEAT drive the floor (how fast it runs at you) and thicken the sun's stripes;
 *   - the LEVEL brightens the grid and the sun's halo; the MIDS light the ridge line; the TREBLE
 *     makes the stars twinkle.
 *
 * THE RIDGE IS SMOOTHED ON THE CPU, rising quickly and falling slowly, because a range of mountains
 * that jitters every frame reads as noise rather than as terrain. Thirty-two points are enough for
 * a skyline; they reach the shader as a uniform array rather than a texture, which keeps this
 * plugin free of GPU objects of its own.
 *****************************************************************************/
import { SPECTRUM_BANDS } from "../audioFrame";
import { fragmentShader } from "../Shader/glsl";
import { ease, wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";

/** Points along the ridge — half the spectrum's bands, each the mean of a pair. */
const RIDGE_POINTS = SPECTRUM_BANDS / 2;

/** How fast a mountain rises to meet a louder band, in seconds. */
const RISE = 0.06;

/** How slowly a mountain sinks once its band falls quiet, in seconds. */
const FALL = 0.45;

/** The picture, per pixel. */
const FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform float uScroll;
uniform float uSunScroll;
uniform float uRidge[${RIDGE_POINTS}];
`,
    // language=GLSL
    `
const float HORIZON = -0.06;
const int RIDGE_LAST = ${RIDGE_POINTS - 1};

// The ridge's height at x (0 = the middle of the screen, 1 = its edge), between the two nearest
// points. Treble sits in the middle and the bass at the edges, so the big peaks frame the sun.
float ridgeAt(float x) {
    float position = (1.0 - clamp(x, 0.0, 1.0)) * float(RIDGE_LAST);
    int index = int(floor(position));
    int next = min(index + 1, RIDGE_LAST);
    return mix(uRidge[index], uRidge[next], fract(position));
}

void main() {
    vec2 p = centred();
    float halfWidth = 0.5 * uResolution.x / min(uResolution.x, uResolution.y);
    vec3 colour;

    if (p.y >= HORIZON) {
        float height = p.y - HORIZON;
        // Sky: violet haze at the horizon, deep space above.
        colour = mix(uMid * 0.45, uSpace, smoothstep(0.0, 0.5, height));

        // Stars, round and sparse, twinkling with the treble.
        vec2 starGrid = p * 90.0;
        vec2 starCell = floor(starGrid);
        float pick = hash12(starCell);
        if (pick > 0.97) {
            float d = length(fract(starGrid) - hash22(starCell));
            float twinkle = 0.5 + 0.5 * sin(uTime * (1.0 + 3.0 * pick) + pick * 40.0);
            colour += uGlow * smoothstep(0.08, 0.0, d) * (0.3 + 0.7 * twinkle * (0.4 + uTreble))
                * smoothstep(0.05, 0.3, height);
        }

        // The sun: magenta at the top to violet at its foot, set high enough that its striped lower
        // part clears the mountains. The stripes start a little above its middle, drift down, and
        // thicken towards the foot and with the bass.
        vec2 sun = vec2(0.0, HORIZON + 0.27);
        float radius = 0.22;
        float d = length(p - sun);
        float below = (sun.y - p.y) / radius;
        float bands = fract(below * 6.0 + uSunScroll);
        float cut = below > -0.2 ? smoothstep(-0.2, 1.0, below) * (0.3 + 0.3 * uBass) : 0.0;
        float sunMask = (1.0 - smoothstep(radius - 0.003, radius, d)) * step(cut, bands);
        vec3 sunColour = mix(uLow, uMid, clamp(0.5 + 0.5 * below, 0.0, 1.0));
        colour += uLow * 0.35 * exp(-max(d - radius, 0.0) * 8.0) * (0.6 + 0.6 * uLevel);
        colour = mix(colour, sunColour * (0.85 + 0.2 * uLevel), sunMask);

        // The mountains, in front of the sun: a dark silhouette with a lit ridge line, low in the
        // middle so the sun shows above them and rising towards the edges.
        float x = abs(p.x) / halfWidth;
        float ridge = HORIZON + 0.025 + (0.04 + 0.26 * ridgeAt(x)) * (0.3 + 0.7 * x)
            + 0.01 * noise(vec2(x * 30.0, 0.0));
        if (p.y < ridge) colour = mix(uSpace, uMid * 0.18, smoothstep(HORIZON, ridge, p.y));
        colour += uHigh * exp(-abs(p.y - ridge) * 260.0) * (0.45 + 0.8 * uMids);
    } else {
        // The floor: a grid in perspective, running towards you, fading out before the horizon
        // where its lines would crowd into a shimmer.
        float below = HORIZON - p.y;
        float depth = 0.12 / below;
        // Three cells per unit of depth: one per unit left the near floor, the part you look at
        // most, an empty plain with a line or two across it.
        vec2 floorPoint = vec2(p.x * depth, depth + uScroll) * 3.0;
        vec2 lineDistance = (0.5 - abs(fract(floorPoint) - 0.5)) / max(fwidth(floorPoint), vec2(1e-4));
        float grid = 1.0 - smoothstep(0.5, 1.5, min(lineDistance.x, lineDistance.y));
        float horizonFade = smoothstep(0.0, 0.06, below);
        colour = uSpace + uLow * grid * (0.5 + 0.6 * uLevel) * horizonFade;
        colour += uMid * 0.5 * exp(-below * 14.0);
    }

    colour *= mix(0.7, 1.0, 1.0 - smoothstep(0.5, 1.3, length(p)));
    outColor = vec4(finish(colour), 1.0);
}
`
);

/** One party's horizon: how far the floor and the sun's stripes have run, and the smoothed ridge. */
function start(gl: WebGL2RenderingContext): ShaderState {
    let scroll = 0;
    let sunScroll = 0;
    const ridge = new Float32Array(RIDGE_POINTS);

    return {
        /** Run the floor and the stripes on, ease the ridge towards the spectrum, set the uniforms. */
        frame({ uniforms, frame, step }) {
            scroll = wrap(scroll + step * (0.5 + frame.bass * 2.2 + frame.beat * 0.6), 1);
            sunScroll = wrap(sunScroll + step * 0.15, 1);

            for (let point = 0; point < RIDGE_POINTS; point++) {
                const target = ((frame.spectrum[point * 2] ?? 0) + (frame.spectrum[point * 2 + 1] ?? 0)) / 2;
                const current = ridge[point] ?? 0;
                ridge[point] = ease(current, target, step, target > current ? RISE : FALL);
            }

            gl.uniform1f(uniforms.uScroll ?? null, scroll);
            gl.uniform1f(uniforms.uSunScroll ?? null, sunScroll);
            gl.uniform1fv(uniforms.uRidge ?? null, ridge);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "synthwave",
    fragment: FRAGMENT,
    uniforms: ["uScroll", "uSunScroll", "uRidge"],
    start
});
