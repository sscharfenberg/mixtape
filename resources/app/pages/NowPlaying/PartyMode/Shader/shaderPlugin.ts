/******************************************************************************
 * shaderPlugin
 * A visualiser that is one fragment shader over the whole screen — which is most of them. The
 * plugin supplies the shader and, optionally, a little state of its own advanced every frame; this
 * supplies everything else.
 *
 * WHAT EVERY SUCH SHADER GETS, set before the plugin's own hook runs (glsl.ts declares them):
 *   - `uResolution` — the drawing buffer, in pixels;
 *   - `uTime` — seconds since the party opened. A float32, so keep what it drives slow: at an
 *     eight-hour party it is still good to a couple of milliseconds;
 *   - `uBass`, `uMids`, `uTreble`, `uLevel` — the AudioFrame's energies, 0-1, smoothed;
 *   - `uBeat` — the rate-limited beat envelope (feed it to `finish`, not to a flash of your own);
 *   - the palette: `uSpace`, `uGrid`, `uLow`, `uMid`, `uHigh`, `uGlow`.
 *
 * STATE THAT MUST STAY SMOOTH FOR HOURS LIVES ON THE CPU. A phase that grows with the music (a
 * scroll, a flow) is integrated in the plugin's hook as a double and wrapped to a period it
 * repeats on before it reaches the shader, which a float32 `uTime` multiplied up cannot promise.
 *****************************************************************************/
import type { AudioFrame } from "../audioFrame";
import type { VisualizerPalette, VisualizerPlugin, VisualizerRenderer } from "../visualizers";
import type { Uniforms } from "./program";
import {
    PALETTE_UNIFORMS,
    buildProgram,
    createContext,
    guardContext,
    releaseContext,
    setPalette,
    uniformLocations
} from "./program";

/** The longest step a frame integrates, in seconds: a tab back from the background resumes, it does not leap. */
const MAX_STEP = 0.1;

/** The uniforms every shader plugin is given — see the module note. */
export const COMMON_UNIFORMS = [
    "uResolution",
    "uTime",
    "uBass",
    "uMids",
    "uTreble",
    "uLevel",
    "uBeat",
    ...PALETTE_UNIFORMS
] as const;

/** What a plugin's per-frame hook is handed. */
export type ShaderFrame = {
    /** The context, with the program in use and the common uniforms already set. */
    gl: WebGL2RenderingContext;
    /** The plugin's own uniforms, by name. */
    uniforms: Uniforms<string>;
    /** This frame's audio. */
    frame: AudioFrame;
    /** Seconds since the party opened. */
    seconds: number;
    /** Seconds since the last frame, clamped — what to advance state by. */
    step: number;
};

/** One party's worth of a plugin's own state, as its `start` answers it. */
export type ShaderState = {
    /** Advance the state by one frame and set the plugin's own uniforms. The program is in use. */
    frame: (context: ShaderFrame) => void;
    /**
     * Rebuild whatever GPU objects the state owns (a texture, say) after a lost context came back —
     * the old ones went with the context. The program has already been rebuilt.
     */
    restore?: (gl: WebGL2RenderingContext) => void;
    /** Delete the GPU objects the state owns. Called while the context is still alive. */
    dispose?: (gl: WebGL2RenderingContext) => void;
};

/** A one-shader visualiser, as a plugin module describes it. */
export type ShaderDefinition = {
    /** Labels errors, so a broken shader names its plugin. */
    name: string;
    /** The fragment shader, built with `fragmentShader` from glsl.ts. */
    fragment: string;
    /** The shader's own uniforms beyond the common ones. */
    uniforms?: readonly string[];
    /**
     * Start one party's state on `gl`. A factory rather than a fixed hook, so a closed and
     * reopened party starts afresh, and so the state can own GPU objects of its own.
     */
    start?: (gl: WebGL2RenderingContext) => ShaderState;
};

/**
 * Set the common uniforms for one frame. The program must be in use. Exported for a plugin that
 * draws more than one pass (Feedback), which still owes each of its shaders the same inputs.
 */
export function setCommon(
    gl: WebGL2RenderingContext,
    uniforms: Uniforms<(typeof COMMON_UNIFORMS)[number]>,
    frame: AudioFrame,
    seconds: number,
    size: { width: number; height: number },
    palette: VisualizerPalette
): void {
    gl.uniform2f(uniforms.uResolution, size.width, size.height);
    gl.uniform1f(uniforms.uTime, seconds);
    gl.uniform1f(uniforms.uBass, frame.bass);
    gl.uniform1f(uniforms.uMids, frame.mid);
    gl.uniform1f(uniforms.uTreble, frame.treble);
    gl.uniform1f(uniforms.uLevel, frame.level);
    gl.uniform1f(uniforms.uBeat, frame.beat);
    setPalette(gl, uniforms, palette);
}

/** Turn a definition into a plugin the registry can load. */
export function shaderPlugin(definition: ShaderDefinition): VisualizerPlugin {
    const extra = definition.uniforms ?? [];

    return {
        /**
         * Build the program on the host's canvas, or answer null where there is no WebGL2. A
         * shader that fails to compile throws (program.ts → `buildProgram`).
         */
        create({ canvas, palette }): VisualizerRenderer | null {
            const gl = createContext(canvas);
            if (gl === null) return null;

            /** Compile the program and look its uniforms up — again after a lost context. */
            const build = () => {
                const program = buildProgram(gl, definition.fragment, definition.name);

                return {
                    program,
                    common: uniformLocations(gl, program, COMMON_UNIFORMS),
                    own: uniformLocations(gl, program, extra)
                };
            };

            let resources: ReturnType<typeof build> | null = build();
            const size = { width: canvas.width, height: canvas.height };
            const state = definition.start?.(gl) ?? null;
            let previous: number | null = null;

            const unguard = guardContext(
                canvas,
                () => {
                    resources = null;
                },
                () => {
                    resources = build();
                    state?.restore?.(gl);
                }
            );

            return {
                /** Remember the drawing buffer's size for the viewport and `uResolution`. */
                resize(width, height) {
                    size.width = width;
                    size.height = height;
                },

                /** Advance the plugin's state to `seconds` and draw one frame. */
                render(frame, seconds) {
                    const step = previous === null ? 0 : Math.min(Math.max(seconds - previous, 0), MAX_STEP);
                    previous = seconds;
                    if (resources === null || gl.isContextLost()) return;

                    gl.viewport(0, 0, size.width, size.height);
                    gl.useProgram(resources.program);
                    setCommon(gl, resources.common, frame, seconds, size, palette);
                    state?.frame({ gl, uniforms: resources.own, frame, seconds, step });
                    gl.drawArrays(gl.TRIANGLES, 0, 3);
                },

                /** Free the plugin's own objects, the program, the listeners and the context. */
                dispose() {
                    unguard();
                    if (!gl.isContextLost()) state?.dispose?.(gl);
                    if (resources !== null && !gl.isContextLost()) gl.deleteProgram(resources.program);
                    resources = null;
                    releaseContext(gl);
                }
            };
        }
    };
}
