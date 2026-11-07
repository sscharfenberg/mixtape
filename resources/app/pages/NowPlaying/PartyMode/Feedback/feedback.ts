/******************************************************************************
 * feedback
 * Trails: every frame redraws the last one a little zoomed and turned, then draws the live
 * waveform over it as a ring — so each ring the music draws drifts outward, spins and fades, and
 * the screen fills with its own echoes. The trick behind the classic psychedelic visualisers.
 *
 * TWO PASSES OVER TWO TEXTURES, which is why this is not a `shaderPlugin`. The feedback pass reads
 * the previous picture from one texture and writes the next into the other; the display pass puts
 * that on the screen; then the two swap. Whatever the display pass adds (the beat's lift, the
 * vignette) is therefore never fed back, so it cannot compound frame after frame.
 *
 * EIGHT BITS A CHANNEL NEVER QUITE REACH BLACK: a dim pixel multiplied by the decay rounds straight
 * back to the value it had, and the trails would leave a permanent haze. So the feedback pass also
 * subtracts a fraction of one step each frame, which is what lets a trail actually end.
 *
 * WHAT DRIVES WHAT: the ring's shape is the WAVEFORM (mirrored round the circle, so it has no seam);
 * the bass widens it and, with the beat, pushes the echoes outward faster; the mids turn them; the
 * level brightens the ink and walks its colour along the ramp.
 *****************************************************************************/
import { WAVEFORM_POINTS } from "../audioFrame";
import { fragmentShader } from "../Shader/glsl";
import { wrap } from "../Shader/motion";
import type { Uniforms } from "../Shader/program";
import { buildProgram, createContext, guardContext, releaseContext, uniformLocations } from "../Shader/program";
import { COMMON_UNIFORMS, setCommon } from "../Shader/shaderPlugin";
import type { VisualizerPlugin, VisualizerRenderer, VisualizerSetup } from "../visualizers";

/** The longest step a frame integrates, in seconds. */
const MAX_STEP = 0.1;

/** How long an echo takes to fade by a factor of e, in seconds. */
const TRAIL = 0.7;

/** The feedback pass: the last picture, zoomed, turned and faded, plus a new ring of waveform. */
const FEEDBACK_FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform sampler2D uPrevious;
uniform sampler2D uWave;
uniform float uZoom;
uniform float uTurn;
uniform float uDecay;
uniform float uHue;
`,
    // language=GLSL
    `
void main() {
    vec2 p = centred();
    float unit = min(uResolution.x, uResolution.y);

    // Read from a point a little further in and turned back, so what was drawn moves outward and
    // spins; a slow wobble keeps the echoes from being perfect circles.
    vec2 source = rotation(uTurn) * p * uZoom;
    source += 0.003 * vec2(sin(p.y * 9.0 + uTime * 0.7), cos(p.x * 8.0 - uTime * 0.6));
    vec3 previous = texture(uPrevious, source * unit / uResolution + 0.5).rgb;
    previous = max(previous * uDecay - 1.5 / 255.0, 0.0);

    // The waveform as a ring, mirrored top to bottom so the circle has no seam where it closes.
    float around = atan(p.y, p.x) / TAU + 0.5;
    float wave = texture(uWave, vec2(abs(around * 2.0 - 1.0), 0.5)).r * 2.0 - 1.0;
    float radius = 0.16 + 0.05 * uBass + wave * (0.05 + 0.12 * uLevel);
    float ring = exp(-pow((length(p) - radius) / 0.0045, 2.0));
    vec3 ink = ramp(uHue) * ring * (0.35 + 0.45 * uLevel);

    outColor = vec4(min(previous + ink, vec3(1.0)), 1.0);
}
`
);

/** The display pass: the newest picture onto the screen, never darker than empty space. */
const DISPLAY_FRAGMENT = fragmentShader(
    // language=GLSL
    `
uniform sampler2D uImage;
`,
    // language=GLSL
    `
void main() {
    vec3 colour = max(texture(uImage, gl_FragCoord.xy / uResolution).rgb, uSpace);
    colour *= mix(0.7, 1.0, 1.0 - smoothstep(0.45, 1.25, length(centred())));
    outColor = vec4(finish(colour), 1.0);
}
`
);

/** Every uniform the feedback pass reads beyond the common ones. */
const FEEDBACK_UNIFORMS = ["uPrevious", "uWave", "uZoom", "uTurn", "uDecay", "uHue"] as const;

/** A program and its uniforms. */
type Pass<Name extends string> = {
    /** The linked program. */
    program: WebGLProgram;
    /** The common uniforms. */
    common: Uniforms<(typeof COMMON_UNIFORMS)[number]>;
    /** The pass's own uniforms. */
    own: Uniforms<Name>;
};

/** A texture with a framebuffer that draws into it. */
type Target = {
    /** The picture. */
    texture: WebGLTexture;
    /** What renders into it. */
    framebuffer: WebGLFramebuffer;
};

/** Compile one pass and look its uniforms up. */
function buildPass<Name extends string>(
    gl: WebGL2RenderingContext,
    fragment: string,
    names: readonly Name[]
): Pass<Name> {
    const program = buildProgram(gl, fragment, "feedback");

    return {
        program,
        common: uniformLocations(gl, program, COMMON_UNIFORMS),
        own: uniformLocations(gl, program, names)
    };
}

/** A texture of `width` × `height` RGBA bytes — blank, as WebGL allocates it — set up for linear sampling. */
function createTexture(gl: WebGL2RenderingContext, width: number, height: number, format: number): WebGLTexture {
    const texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texImage2D(gl.TEXTURE_2D, 0, format, width, height, 0, format === gl.R8 ? gl.RED : gl.RGBA, gl.UNSIGNED_BYTE, null);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

    return texture;
}

/** A blank picture of the drawing buffer's size, with the framebuffer that draws into it. */
function createTarget(gl: WebGL2RenderingContext, width: number, height: number): Target {
    const texture = createTexture(gl, width, height, gl.RGBA8);
    const framebuffer = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);

    return { texture, framebuffer };
}

/** Delete a target's texture and framebuffer. */
function deleteTarget(gl: WebGL2RenderingContext, target: Target): void {
    gl.deleteFramebuffer(target.framebuffer);
    gl.deleteTexture(target.texture);
}

/**
 * Build the renderer on the host's canvas, or answer null where there is no WebGL2. A shader that
 * fails to compile throws, as every plugin's does.
 */
function create({ canvas, palette }: VisualizerSetup): VisualizerRenderer | null {
    const gl = createContext(canvas);
    if (gl === null) return null;

    /** Everything the context owns, rebuilt as a set after it is lost. */
    const build = (width: number, height: number) => {
        gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);

        return {
            feedback: buildPass(gl, FEEDBACK_FRAGMENT, FEEDBACK_UNIFORMS),
            display: buildPass(gl, DISPLAY_FRAGMENT, ["uImage"] as const),
            wave: createTexture(gl, WAVEFORM_POINTS, 1, gl.R8),
            targets: [createTarget(gl, width, height), createTarget(gl, width, height)] as [Target, Target]
        };
    };

    const size = { width: Math.max(canvas.width, 1), height: Math.max(canvas.height, 1) };
    let resources: ReturnType<typeof build> | null = build(size.width, size.height);
    let previous: number | null = null;
    let hue = 0;
    const waveBytes = new Uint8Array(WAVEFORM_POINTS);

    const unguard = guardContext(
        canvas,
        () => {
            resources = null;
        },
        () => {
            resources = build(size.width, size.height);
        }
    );

    return {
        /** A new size means new pictures — the old ones were the wrong shape, and the trails restart. */
        resize(width, height) {
            size.width = width;
            size.height = height;
            if (resources === null || gl.isContextLost()) return;
            for (const target of resources.targets) deleteTarget(gl, target);
            resources.targets = [createTarget(gl, width, height), createTarget(gl, width, height)];
        },

        /** Feed the last picture back with this frame's ring, show it, and swap the two. */
        render(frame, seconds) {
            const step = previous === null ? 0 : Math.min(Math.max(seconds - previous, 0), MAX_STEP);
            previous = seconds;
            hue = wrap(hue + step * (0.05 + frame.level * 0.1), 1);
            if (resources === null || gl.isContextLost()) return;

            const [read, write] = resources.targets;
            for (let point = 0; point < WAVEFORM_POINTS; point++) {
                waveBytes[point] = Math.round((Math.max(-1, Math.min(1, frame.waveform[point] ?? 0)) * 0.5 + 0.5) * 255);
            }
            gl.activeTexture(gl.TEXTURE1);
            gl.bindTexture(gl.TEXTURE_2D, resources.wave);
            gl.texSubImage2D(gl.TEXTURE_2D, 0, 0, 0, WAVEFORM_POINTS, 1, gl.RED, gl.UNSIGNED_BYTE, waveBytes);

            const { feedback, display } = resources;
            gl.viewport(0, 0, size.width, size.height);

            gl.bindFramebuffer(gl.FRAMEBUFFER, write.framebuffer);
            gl.useProgram(feedback.program);
            setCommon(gl, feedback.common, frame, seconds, size, palette);
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, read.texture);
            gl.uniform1i(feedback.own.uPrevious, 0);
            gl.uniform1i(feedback.own.uWave, 1);
            // Per-frame factors from the clock, so the trails move at one speed at any frame rate.
            gl.uniform1f(feedback.own.uZoom, Math.exp(-step * (0.35 + 1.4 * frame.bass + 0.8 * frame.beat)));
            gl.uniform1f(feedback.own.uTurn, step * (0.08 + 0.4 * frame.mid));
            gl.uniform1f(feedback.own.uDecay, Math.exp(-step / TRAIL));
            gl.uniform1f(feedback.own.uHue, Math.abs(hue * 2 - 1));
            gl.drawArrays(gl.TRIANGLES, 0, 3);

            gl.bindFramebuffer(gl.FRAMEBUFFER, null);
            gl.useProgram(display.program);
            setCommon(gl, display.common, frame, seconds, size, palette);
            gl.bindTexture(gl.TEXTURE_2D, write.texture);
            gl.uniform1i(display.own.uImage, 0);
            gl.drawArrays(gl.TRIANGLES, 0, 3);

            resources.targets = [write, read];
        },

        /** Free both programs, all three textures, the listeners and the context. */
        dispose() {
            unguard();
            if (resources !== null && !gl.isContextLost()) {
                gl.deleteProgram(resources.feedback.program);
                gl.deleteProgram(resources.display.program);
                gl.deleteTexture(resources.wave);
                for (const target of resources.targets) deleteTarget(gl, target);
            }
            resources = null;
            releaseContext(gl);
        }
    };
}

/** The plugin, as the registry loads it. */
const feedback: VisualizerPlugin = { create };

export default feedback;
