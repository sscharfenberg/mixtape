/******************************************************************************
 * eqWarp
 * The EQ warp: a flight down a winding tunnel whose walls are the music — the spectrum's recent
 * history laid out round and along the tube (see tunnelMath), with stars streaking past inside it
 * and a light at the far end that breathes with the level.
 *
 * WHAT THE RENDERER DECIDES, as opposed to the shader: how fast to fly (the bass, smoothed, plus a
 * nudge on each beat), how the tube rolls, where along its winding path the camera is, and which
 * history rows are due. All of it is integrated from the clock, never counted in frames.
 *
 * THE ONE GPU OBJECT IT OWNS is the history texture, 256 rows of 64 bytes; the only per-frame
 * upload is a row or two into it. A lost context takes it along, and the restore starts it empty —
 * the walls are dark for a few seconds and fill as the flight writes new rows, which reads as
 * flying into fresh music and is not worth keeping a copy on the CPU to avoid.
 *****************************************************************************/
import { SPECTRUM_BANDS } from "../audioFrame";
import { ease, wrap } from "../Shader/motion";
import type { ShaderState } from "../Shader/shaderPlugin";
import { shaderPlugin } from "../Shader/shaderPlugin";
import { STAR_PERIOD, STAR_ROWS, TUBE_UNIFORMS, setTube, windingPath } from "../WindingTube/windingTube";
import { FRAGMENT_SHADER } from "./shaders";
import { HISTORY_LENGTH, HISTORY_ROWS, ROW_LENGTH, SPAWN_DEPTH, encodeRow, rowsToWrite } from "./tunnelMath";

/** Cruising speed with nothing playing, in world units a second: a slow drift, never a halt. */
const CRUISE = 0.55;

/** How much the bass adds to the speed at full level. */
const BASS_THRUST = 2.6;

/** How much a beat adds, decaying with the beat envelope. */
const BEAT_THRUST = 0.9;

/** How quickly the speed follows its target, in seconds — a lurch, not a teleport. */
const SPEED_LAG = 0.15;

/** Where the light at the end sits: where the walls start fading out (the shader's spawn fade). */
const GLOW_DEPTH = SPAWN_DEPTH - 1.5;

/** Allocate the history texture, empty, and leave it bound. */
function createHistory(gl: WebGL2RenderingContext): WebGLTexture {
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

    return history;
}

/**
 * One party's flight: position, speed, roll, the path, and the history texture. Every piece of
 * it lives in this closure, so two parties never share a position.
 */
function start(gl: WebGL2RenderingContext): ShaderState {
    let history = createHistory(gl);
    let travel = 0;
    let speed = CRUISE;
    let spin = 0;
    let lastRow = Math.floor(SPAWN_DEPTH / ROW_LENGTH);
    const row = new Uint8Array(SPECTRUM_BANDS);
    const path = windingPath();

    return {
        /** Fly one frame on, write the rows that came due, and set the uniforms. */
        frame({ uniforms, frame, step }) {
            speed = ease(speed, CRUISE + frame.bass * BASS_THRUST + frame.beat * BEAT_THRUST, step, SPEED_LAG);
            travel += speed * step;
            // A slow roll that the mids hurry along — the tunnel turning, not the music spinning.
            spin += step * (0.03 + frame.mid * 0.25);

            // New rows at the far end, for however far the flight went this frame.
            gl.activeTexture(gl.TEXTURE0);
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
            gl.uniform1i(uniforms.uHistory ?? null, 0);

            // Wrapped on the CPU, where the clock is a double: a float32 in the shader would lose
            // the grid's precision after a few hours of flying. Both periods are exact multiples of
            // what they wrap, so the wrap itself is invisible.
            gl.uniform1f(uniforms.uTravel ?? null, wrap(travel, HISTORY_LENGTH));
            gl.uniform1f(uniforms.uStarTravel ?? null, wrap(travel * 1.6, STAR_PERIOD / STAR_ROWS));
            gl.uniform1f(uniforms.uSpin ?? null, wrap(spin, Math.PI * 2));
            gl.uniform1f(uniforms.uSpeed ?? null, speed);
            setTube(gl, uniforms, path, travel, GLOW_DEPTH);
        },

        /** A fresh, empty history after a lost context — see the module note. */
        restore(context) {
            history = createHistory(context);
            lastRow = Math.floor((travel + SPAWN_DEPTH) / ROW_LENGTH);
        },

        /** Delete the history texture. */
        dispose(context) {
            context.deleteTexture(history);
        }
    };
}

/** The plugin, as the registry loads it. */
export default shaderPlugin({
    name: "eqWarp",
    fragment: FRAGMENT_SHADER,
    uniforms: ["uTravel", "uStarTravel", "uSpin", "uSpeed", "uHistory", ...TUBE_UNIFORMS],
    start
});
