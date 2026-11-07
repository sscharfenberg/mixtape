/******************************************************************************
 * plasma
 * The demoscene classic: a field of interfering sine waves, flowing through the level ramp. The
 * cheapest picture in party mode — four sines a pixel — and the calmest.
 *
 * WHAT DRIVES WHAT: the level speeds the flow, the bass breathes the field in and out, the mids
 * drift the colours along the ramp.
 *
 * THE FLOW STAYS SMOOTH FOR HOURS because it is a phase integrated on the CPU and wrapped at a full
 * turn, and every wave advances by a WHOLE multiple of it — so after a wrap each wave is exactly
 * where it was. A shader multiplying a float32 clock up would slowly lose that.
 *
 * IT IS DIMMED ON PURPOSE, mixed most of the way towards empty space: a full-strength plasma fills
 * the whole screen with the brightest colours in the palette, which is a lot of light to sit in.
 *****************************************************************************/
import { fragmentShader } from "../Shader/glsl";
import { ease, wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";

/** The picture, per pixel. */
const FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform float uFlow;
uniform float uShift;
uniform float uScale;
`,
    // language=GLSL
    `
void main() {
    vec2 screen = centred();
    vec2 p = screen * uScale;
    // Whole multiples of the flow (1, 2, 3, 2) — see the file note.
    float v = sin(p.x * 1.7 + uFlow)
        + sin(p.y * 2.3 - 2.0 * uFlow)
        + sin((p.x + p.y) * 1.3 + 3.0 * uFlow)
        + sin(length(p * vec2(1.0, 1.3)) * 2.9 - 2.0 * uFlow);
    float t = 0.5 + 0.5 * sin(v * 1.6 + uShift * TAU);

    vec3 colour = mix(uSpace, ramp(t), 0.45 + 0.35 * uLevel);
    colour *= mix(0.7, 1.0, 1.0 - smoothstep(0.4, 1.3, length(screen)));
    outColor = vec4(finish(colour), 1.0);
}
`
);

/** One party's plasma: its flow, its colour drift and its breathing scale. */
function start(gl: WebGL2RenderingContext): ShaderState {
    let flow = 0;
    let shift = 0;
    let scale = 3;

    return {
        /** Flow on, drift the colours, breathe with the bass, and set the uniforms. */
        frame({ uniforms, frame, step }) {
            flow = wrap(flow + step * (0.25 + frame.level * 1.2), Math.PI * 2);
            shift = wrap(shift + step * (0.02 + frame.mid * 0.1), 1);
            scale = ease(scale, 3 * (1 - 0.2 * frame.bass), step, 0.2);

            gl.uniform1f(uniforms.uFlow ?? null, flow);
            gl.uniform1f(uniforms.uShift ?? null, shift);
            gl.uniform1f(uniforms.uScale ?? null, scale);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "plasma",
    fragment: FRAGMENT,
    uniforms: ["uFlow", "uShift", "uScale"],
    start
});
