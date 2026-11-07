/******************************************************************************
 * julia
 * A Julia fractal the music steers: its shape morphs as its constant travels a circle, it pumps
 * with the bass, and its colours run through the level ramp.
 *
 * A JULIA SET RATHER THAN A MANDELBROT ZOOM. A zoom into the Mandelbrot set is what a fractal
 * visualiser usually means, and on a GPU it ends after fifteen or twenty seconds: a float32
 * coordinate runs out of digits and the picture turns to blocks, and going deeper takes precision
 * tricks that cost many times the work. A Julia set never zooms; its whole shape is decided by one
 * complex constant, so moving that constant gives endless, smooth change at a fixed cost — and a
 * constant is exactly the kind of thing music can steer.
 *
 * THE CONSTANT RIDES THE EDGE OF THE MANDELBROT SET'S MAIN CARDIOID, c = e^iθ/2 − e^2iθ/4, breathing
 * a little either side of it. Every Julia set for a c on that edge is connected and as detailed as
 * Julia sets get — spirals, rabbits, cauliflowers, the basilica — where a plain circle of c values
 * crosses regions whose sets are scattered dust, a screen that is all but black. The mids hurry it
 * along; the bass pulls the camera in.
 *
 * COST: up to ITERATIONS steps of one complex multiplication per pixel, stopped early outside the
 * set. Desktop GPUs do not notice; a phone is drawn at no more than one pixel per CSS pixel and the
 * host steps the resolution down once if frames come late.
 *****************************************************************************/
import { fragmentShader } from "../Shader/glsl";
import { ease, wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";

/** How far the constant breathes either side of the cardioid's edge, as a fraction of it. */
const BREATH = 0.025;

/**
 * The constant for a point θ along the main cardioid's edge, scaled by `scale` (1 is on the edge) —
 * see the module note.
 */
export function cardioidPoint(angle: number, scale: number): [number, number] {
    return [
        scale * (0.5 * Math.cos(angle) - 0.25 * Math.cos(2 * angle)),
        scale * (0.5 * Math.sin(angle) - 0.25 * Math.sin(2 * angle))
    ];
}

/**
 * How far from the centre the screen's short side reaches, at rest — close enough that the set
 * fills the screen; its outermost tendrils may leave it, which reads as being inside it.
 */
const VIEW = 1.15;

/** The picture, per pixel. */
const FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform vec2 uC;
uniform float uZoom;
uniform float uTurn;
uniform float uHue;
`,
    // language=GLSL
    `
const int ITERATIONS = 96;

void main() {
    vec2 z = rotation(uTurn) * centred() * 2.0 * uZoom;

    float n = 0.0;
    bool escaped = false;
    for (int i = 0; i < ITERATIONS; i++) {
        z = vec2(z.x * z.x - z.y * z.y, 2.0 * z.x * z.y) + uC;
        if (dot(z, z) > 256.0) {
            escaped = true;
            break;
        }
        n += 1.0;
    }

    // Inside the set is empty space; outside, the smoothed escape count decides the colour (no
    // banding between whole iteration counts) and how bright — brightest close to the set's edge.
    vec3 colour = uSpace;
    if (escaped) {
        float smoothN = n + 1.0 - log2(0.5 * log2(dot(z, z)));
        float edge = smoothstep(1.0, 28.0, smoothN);
        // A ping-pong along the ramp rather than a wrap, so the colour never jumps from blue back
        // to magenta between neighbouring bands.
        float along = abs(fract(smoothN * 0.03 + uHue) * 2.0 - 1.0);
        colour = ramp(along) * edge * (0.55 + 0.45 * uLevel);
        colour += uGlow * pow(edge, 4.0) * 0.35;
    }

    outColor = vec4(finish(colour), 1.0);
}
`
);

/** One party's fractal: where the constant is on its circle, the zoom, the turn and the colour drift. */
function start(gl: WebGL2RenderingContext): ShaderState {
    let angle = Math.random() * Math.PI * 2;
    let breath = 0;
    let zoom = VIEW;
    let turn = 0;
    let hue = 0;

    return {
        /** Move the constant and the camera on, and set the uniforms. */
        frame({ uniforms, frame, step }) {
            angle = wrap(angle + step * (0.04 + frame.mid * 0.22), Math.PI * 2);
            breath = wrap(breath + step * 0.11, Math.PI * 2);
            zoom = ease(zoom, VIEW * (1 - 0.12 * frame.bass - 0.04 * frame.beat), step, 0.12);
            turn = wrap(turn + step * 0.02, Math.PI * 2);
            hue = wrap(hue + step * (0.015 + frame.level * 0.05), 1);

            const [x, y] = cardioidPoint(angle, 1 + BREATH * Math.sin(breath));
            gl.uniform2f(uniforms.uC ?? null, x, y);
            gl.uniform1f(uniforms.uZoom ?? null, zoom);
            gl.uniform1f(uniforms.uTurn ?? null, turn);
            gl.uniform1f(uniforms.uHue ?? null, hue);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "julia",
    fragment: FRAGMENT,
    uniforms: ["uC", "uZoom", "uTurn", "uHue"],
    start
});
