/******************************************************************************
 * warpTunnel
 * Party mode's first visualiser: a flight down a tunnel whose walls are the music — the spectrum's
 * recent history laid out round and along a tube (see tunnelMath), with stars streaking past
 * inside it and a light at the far end that breathes with the level.
 *
 * WebGL2 AND NOTHING ELSE. One full-screen triangle and one fragment shader (shaders.ts); the only
 * per-frame upload is a row or two of 64 bytes into the history texture. No library — the whole
 * picture is a few dozen lines of GLSL, and a dependency would cost more to read than this does.
 *
 * WHAT THE RENDERER DECIDES, as opposed to the shader: how fast to fly (the bass, smoothed, plus a
 * nudge on each beat), how the tube rolls, where along its winding path the camera is, and which
 * history rows are due. All of it is integrated from the clock the host passes, never counted in
 * frames, so a 120 Hz screen and a phone at 30 fps fly the same tunnel at the same speed. The
 * path's phases are rolled once per party, so no two flights wind the same way.
 *
 * A LOST CONTEXT IS SURVIVABLE. A phone reclaims GPU memory from a backgrounded tab, and the
 * canvas then draws nothing until the context is restored; the renderer cancels the loss's
 * default (which is what makes a restore possible at all) and rebuilds everything on restore.
 *****************************************************************************/
import type { AudioFrame } from "../audioFrame";
import { SPECTRUM_BANDS } from "../audioFrame";
import type { Rgb, VisualizerPlugin, VisualizerRenderer, VisualizerSetup } from "../visualizers";
import { FRAGMENT_SHADER, VERTEX_SHADER } from "./shaders";
import {
    HISTORY_LENGTH,
    HISTORY_ROWS,
    ROW_LENGTH,
    SPAWN_DEPTH,
    STAR_PERIOD,
    PATH_SAMPLES,
    STAR_ROWS,
    encodeRow,
    pathCentres,
    pathOffset,
    rowsToWrite,
    wrap
} from "./tunnelMath";

/** Cruising speed with nothing playing, in world units a second: a slow drift, never a halt. */
const CRUISE = 0.55;

/** How much the bass adds to the speed at full level. */
const BASS_THRUST = 2.6;

/** How much a beat adds, decaying with the beat envelope. */
const BEAT_THRUST = 0.9;

/** How quickly the speed follows its target, in seconds — a lurch, not a teleport. */
const SPEED_LAG = 0.15;

/**
 * The depth the light at the end sits at: where the walls start fading out (the shader's spawn
 * fade), so it marks the end of the tunnel you can see however the path bends beyond it.
 */
const GLOW_DEPTH = SPAWN_DEPTH - 1.5;

/** The longest step the flight integrates at once, in seconds — see `render`. */
const MAX_STEP = 0.1;

/** Every uniform the fragment shader declares, by name. */
const UNIFORMS = [
    "uResolution",
    "uTravel",
    "uStarTravel",
    "uSpin",
    "uPath",
    "uGlowCentre",
    "uSpeed",
    "uTreble",
    "uLevel",
    "uBeat",
    "uHistory",
    "uSpace",
    "uGrid",
    "uLow",
    "uMid",
    "uHigh",
    "uGlow"
] as const;

/** A uniform's name. */
type UniformName = (typeof UNIFORMS)[number];

/** The GPU-side objects — rebuilt as a set after a lost context, so they travel together. */
type Resources = {
    /** The linked program. */
    program: WebGLProgram;
    /** The spectrum history, HISTORY_ROWS rows of SPECTRUM_BANDS bytes. */
    history: WebGLTexture;
    /** Where each uniform lives in the program. */
    uniforms: Record<UniformName, WebGLUniformLocation | null>;
};

/**
 * Compile one shader stage, throwing with the driver's own log when it fails.
 *
 * A throw rather than null, because a shader that will not compile is a BUG in this file, not a
 * browser without support — the host reports it instead of quietly showing no picture.
 */
function compile(gl: WebGL2RenderingContext, type: number, source: string): WebGLShader {
    const shader = gl.createShader(type);
    if (shader === null) throw new Error("warpTunnel: could not create a shader");

    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS) && !gl.isContextLost()) {
        const log = gl.getShaderInfoLog(shader);
        gl.deleteShader(shader);
        throw new Error(`warpTunnel: shader did not compile — ${log ?? "no log"}`);
    }

    return shader;
}

/** Compile, link and look up everything the renderer needs, and allocate the history texture. */
function build(gl: WebGL2RenderingContext): Resources {
    const vertex = compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
    const fragment = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT_SHADER);
    const program = gl.createProgram();

    gl.attachShader(program, vertex);
    gl.attachShader(program, fragment);
    gl.linkProgram(program);
    // The stages are part of the program once linked; nothing else will ever ask for them.
    gl.deleteShader(vertex);
    gl.deleteShader(fragment);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS) && !gl.isContextLost()) {
        throw new Error(`warpTunnel: program did not link — ${gl.getProgramInfoLog(program) ?? "no log"}`);
    }

    const history = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, history);
    // One byte a texel and 64 a row: aligned already, but say so rather than rely on the default.
    gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
    gl.texImage2D(
        gl.TEXTURE_2D,
        0,
        gl.R8,
        SPECTRUM_BANDS,
        HISTORY_ROWS,
        0,
        gl.RED,
        gl.UNSIGNED_BYTE,
        new Uint8Array(SPECTRUM_BANDS * HISTORY_ROWS)
    );
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    // Bands clamp (bass and treble are not neighbours); rows REPEAT, because the buffer is a ring.
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.REPEAT);

    const uniforms = Object.fromEntries(UNIFORMS.map(name => [name, gl.getUniformLocation(program, name)])) as Record<
        UniformName,
        WebGLUniformLocation | null
    >;

    return { program, history, uniforms };
}

/**
 * Build the renderer on the host's canvas, or answer null where there is no WebGL2.
 *
 * Every piece of flight state lives in this closure, so two parties (close and reopen) never
 * share a position — and so does the row buffer, allocated once.
 */
function create(setup: VisualizerSetup): VisualizerRenderer | null {
    const { canvas, palette } = setup;
    const gl = canvas.getContext("webgl2", {
        alpha: false,
        antialias: false,
        depth: false,
        stencil: false,
        premultipliedAlpha: false,
        preserveDrawingBuffer: false,
        powerPreference: "high-performance"
    });
    if (gl === null) return null;

    let resources: Resources | null = build(gl);
    let width = canvas.width;
    let height = canvas.height;

    let previous: number | null = null;
    let travel = 0;
    let speed = CRUISE;
    let spin = 0;
    let lastRow = Math.floor(SPAWN_DEPTH / ROW_LENGTH);
    const row = new Uint8Array(SPECTRUM_BANDS);
    // Where on the path this party starts, per sine component — random, so every party winds its
    // own way — and the sampled rings' screen centres, rewritten in place every frame.
    const curveOffsets = Array.from({ length: 4 }, () => Math.random() * Math.PI * 2);
    const path = new Float32Array(PATH_SAMPLES * 2);

    /**
     * Let the browser restore the context. Without `preventDefault()` a lost WebGL context stays
     * lost for good; with it, `webglcontextrestored` follows once the GPU has memory again.
     */
    const onLost = (event: Event): void => {
        event.preventDefault();
        resources = null;
    };

    /**
     * Rebuild everything the lost context took with it. The history comes back empty, so the
     * walls are dark for a few seconds and fill as the flight writes new rows — which reads as
     * flying into fresh music, and is not worth persisting a texture on the CPU to avoid.
     */
    const onRestored = (): void => {
        resources = build(gl);
        lastRow = Math.floor((travel + SPAWN_DEPTH) / ROW_LENGTH);
    };

    canvas.addEventListener("webglcontextlost", onLost);
    canvas.addEventListener("webglcontextrestored", onRestored);

    /** Set one colour uniform from a palette entry. */
    const colour = (location: WebGLUniformLocation | null, rgb: Rgb): void => {
        gl.uniform3f(location, rgb[0], rgb[1], rgb[2]);
    };

    return {
        /** Remember the drawing buffer's size for the viewport and the shader's centring. */
        resize(nextWidth, nextHeight) {
            width = nextWidth;
            height = nextHeight;
        },

        /**
         * Advance the flight to `seconds` and draw.
         *
         * The step is clamped to MAX_STEP, so a tab that comes back from minutes in the background
         * resumes the flight rather than leaping a few hundred units down the tube in one frame.
         */
        render(frame: AudioFrame, seconds: number) {
            const step = previous === null ? 0 : Math.min(Math.max(seconds - previous, 0), MAX_STEP);
            previous = seconds;

            const target = CRUISE + frame.bass * BASS_THRUST + frame.beat * BEAT_THRUST;
            speed += (target - speed) * (1 - Math.exp(-step / SPEED_LAG));
            travel += speed * step;
            // A slow roll that the mids hurry along — the tunnel turning, not the music spinning.
            spin += step * (0.03 + frame.mid * 0.25);

            if (resources === null || gl.isContextLost()) return;
            const { program, history, uniforms } = resources;

            // New rows at the far end, for however far the flight went this frame.
            gl.bindTexture(gl.TEXTURE_2D, history);
            encodeRow(frame.spectrum, row);
            const targetRow = Math.floor((travel + SPAWN_DEPTH) / ROW_LENGTH);
            for (const absolute of rowsToWrite(lastRow, targetRow, HISTORY_ROWS)) {
                gl.texSubImage2D(
                    gl.TEXTURE_2D,
                    0,
                    0,
                    wrap(absolute, HISTORY_ROWS),
                    SPECTRUM_BANDS,
                    1,
                    gl.RED,
                    gl.UNSIGNED_BYTE,
                    row
                );
            }
            lastRow = Math.max(lastRow, targetRow);

            gl.viewport(0, 0, width, height);
            gl.useProgram(program);
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, history);
            gl.uniform1i(uniforms.uHistory, 0);
            gl.uniform2f(uniforms.uResolution, width, height);
            // Wrapped on the CPU, where the clock is a double: a float32 in the shader would lose
            // the grid's precision after a few hours of flying. Both periods are exact multiples of
            // what they wrap, so the wrap itself is invisible.
            gl.uniform1f(uniforms.uTravel, wrap(travel, HISTORY_LENGTH));
            gl.uniform1f(uniforms.uStarTravel, wrap(travel * 1.6, STAR_PERIOD / STAR_ROWS));
            gl.uniform1f(uniforms.uSpin, wrap(spin, Math.PI * 2));
            // The path where the camera is now, in double precision (tunnelMath → pathCentres).
            pathCentres(travel, curveOffsets, path);
            gl.uniform2fv(uniforms.uPath, path);
            const [glowX, glowY] = pathOffset(travel, curveOffsets, GLOW_DEPTH);
            gl.uniform2f(uniforms.uGlowCentre, glowX / GLOW_DEPTH, glowY / GLOW_DEPTH);
            gl.uniform1f(uniforms.uSpeed, speed);
            gl.uniform1f(uniforms.uTreble, frame.treble);
            gl.uniform1f(uniforms.uLevel, frame.level);
            gl.uniform1f(uniforms.uBeat, frame.beat);
            colour(uniforms.uSpace, palette.space);
            colour(uniforms.uGrid, palette.grid);
            colour(uniforms.uLow, palette.low);
            colour(uniforms.uMid, palette.mid);
            colour(uniforms.uHigh, palette.high);
            colour(uniforms.uGlow, palette.glow);

            gl.drawArrays(gl.TRIANGLES, 0, 3);
        },

        /** Free the GPU objects and the listeners; the canvas is the host's to remove. */
        dispose() {
            canvas.removeEventListener("webglcontextlost", onLost);
            canvas.removeEventListener("webglcontextrestored", onRestored);
            if (resources !== null && !gl.isContextLost()) {
                gl.deleteProgram(resources.program);
                gl.deleteTexture(resources.history);
            }
            resources = null;
            // Hand the GPU memory back now rather than whenever the canvas is collected — a
            // party can be opened and closed many times in one session.
            gl.getExtension("WEBGL_lose_context")?.loseContext();
        }
    };
}

/** The plugin, as the registry loads it. */
const warpTunnel: VisualizerPlugin = { create };

export default warpTunnel;
