/******************************************************************************
 * tunnelMath
 * The warp tunnel's geometry, as numbers the shader and the renderer both read — and the two
 * pieces of bookkeeping that are worth testing without a GPU.
 *
 * THE WALLS ARE THE SPECTRUM, FIXED IN SPACE. Every frame the current spectrum is written as one
 * row of a texture at the far end of the tunnel, and the camera flies forward through what was
 * written: angle round the tube is pitch (bass at the floor, treble at the roof), distance along
 * it is time. So the rings rushing past are the last few seconds of the song, and a faster flight
 * (the bass drives the speed) spaces the same music further apart.
 *
 * The texture is a RING BUFFER in world distance rather than in time: row `n` holds the spectrum
 * at world position `n × ROW_LENGTH`, and the shader samples it by where a pixel's wall is. That
 * is what keeps the walls still while the camera moves — a buffer indexed by time would slide the
 * whole pattern every time the speed changed.
 *****************************************************************************/

/** How many rows of spectrum the history texture holds. */
export const HISTORY_ROWS = 256;

/** How much world distance one row covers. */
export const ROW_LENGTH = 1 / 32;

/** The world distance the whole buffer spans — the period everything along the tube repeats on. */
export const HISTORY_LENGTH = HISTORY_ROWS * ROW_LENGTH;

/**
 * How far ahead of the camera a new row is written, in world units. Rows beyond it are stale (the
 * buffer has wrapped onto them) and the shader fades them out, so rings emerge from the dark here.
 * Must stay under HISTORY_LENGTH, or the buffer would overwrite rows that are still on screen.
 */
export const SPAWN_DEPTH = 7;

/**
 * The tube's scale: a pixel `r` from the centre (in units of the screen's short side) sees the
 * wall `TUBE / r` ahead. 0.32 puts the near edge of a 16:9 screen about a third of a unit away.
 */
export const TUBE = 0.32;

/**
 * The length of one ring of cells along the tube. It must divide HISTORY_LENGTH EXACTLY IN BINARY,
 * so the grid wraps seamlessly in a float32 shader too: 0.25 does, where 0.2 divides 8 only in
 * decimal and would leave a one-frame seam on every lap.
 */
export const RING_LENGTH = 0.25;

/** Cells around the tube — 32 per side, mirrored, so each side reads as one EQ of half the bands' count. */
export const SEGMENTS = 64;

/** The inner tube the stars ride on: smaller, so they pass faster than the walls (parallax). */
export const STAR_TUBE = 0.11;

/** Star cells per world unit along the inner tube. */
export const STAR_ROWS = 3;

/**
 * The star field's period in cells. The renderer wraps the stars' travel at `STAR_PERIOD /
 * STAR_ROWS` units and the shader hashes cell rows modulo the same number, so the wrap swaps a row
 * for an identical one instead of reshuffling the sky.
 */
export const STAR_PERIOD = 3000;

/** One sine component of the tunnel's path: how fast it winds (per world unit) and how far. */
export type CurveComponent = {
    /** Wavenumber, radians per world unit — 0.25 is one full swing every ~25 units of flight. */
    k: number;
    /** How far the tube's centre swings, in world units (the tube's radius is TUBE, 0.32). */
    amplitude: number;
};

/**
 * THE TUNNEL WINDS. Its centre line is a sum of two sines per axis, with wavenumbers that share no
 * simple ratio, so the bends never settle into a pattern the eye can learn; the phases are rolled
 * afresh for every party (the renderer's `curveOffsets`), so no two flights wind the same way.
 */
export const CURVE: { x: readonly [CurveComponent, CurveComponent]; y: readonly [CurveComponent, CurveComponent] } = {
    x: [
        { k: 0.25, amplitude: 1.2 },
        { k: 0.41, amplitude: 0.6 }
    ],
    y: [
        { k: 0.19, amplitude: 0.9 },
        { k: 0.53, amplitude: 0.45 }
    ]
};

/** How many rings of the path the shader is given each frame — see {@link pathCentres}. */
export const PATH_SAMPLES = 40;

/** The nearest sampled ring's depth: closer than anything on screen, the stars' inner tube included. */
export const PATH_NEAR = 0.1;

/** The farthest sampled ring's depth: past the spawn fade, beyond which nothing is drawn. */
export const PATH_FAR = SPAWN_DEPTH + 1;

/**
 * The depth of sample `index`, spaced geometrically from PATH_NEAR to PATH_FAR — evenly in what
 * the eye sees, since a ring's size on screen goes as one over its depth.
 */
export function pathDepth(index: number): number {
    return PATH_NEAR * (PATH_FAR / PATH_NEAR) ** (index / (PATH_SAMPLES - 1));
}

/**
 * How far the tube's centre `depth` units ahead of the camera sits from the camera's line of sight,
 * in world units: the path's value there, minus its value AND its slope at the camera. Taking the
 * slope out is what points the camera along the tube, so the near rings stay centred and only the
 * far end swings away.
 *
 * In double precision from the unwrapped travel, on purpose — the shader's own `uTravel` wraps
 * once a lap, and a path read from it would snap back to its first bend.
 */
export function pathOffset(travel: number, offsets: readonly number[], depth: number): [number, number] {
    /** One axis: the sum of its two components, relative to the camera's position and heading. */
    const axis = (components: readonly CurveComponent[], first: number): number =>
        components.reduce((total, { k, amplitude }, index) => {
            const phase = k * travel + (offsets[first + index] ?? 0);

            return (
                total + amplitude * (Math.sin(phase + k * depth) - Math.sin(phase) - k * depth * Math.cos(phase))
            );
        }, 0);

    return [axis(CURVE.x, 0), axis(CURVE.y, 2)];
}

/**
 * Where each sampled ring's centre lands on the screen, written into `out` as x, y pairs — the
 * shader's `uPath`. A ring `d` ahead is drawn `1 / d` the size, so its centre is the path's offset
 * divided by its depth.
 *
 * WHY THE CPU DRAWS THE PATH AND EVERY PIXEL WALKS IT (shaders.ts → `hit`): with the tube winding,
 * one screen point can lie on more than one ring — the near wall on the outside of a bend, and
 * the far tube behind it. Only the NEAREST is what you see, and solving per pixel from a guess
 * finds whichever root it falls into: drawn, that is a worm wriggling at the end of the tunnel.
 * Walking these rings from near to far finds the first wall the line of sight leaves the tube
 * through — and costs the shader no trigonometry at all.
 */
export function pathCentres(travel: number, offsets: readonly number[], out: Float32Array): void {
    for (let index = 0; index < PATH_SAMPLES; index++) {
        const depth = pathDepth(index);
        const [x, y] = pathOffset(travel, offsets, depth);
        out[index * 2] = x / depth;
        out[index * 2 + 1] = y / depth;
    }
}

/** Wrap `value` into [0, period) — a modulo that is also right for negative numbers. */
export function wrap(value: number, period: number): number {
    return ((value % period) + period) % period;
}

/**
 * Which absolute rows to write so the buffer is filled up to `targetRow`, given that `lastRow` is
 * the last one written.
 *
 * Capped at the buffer's capacity, keeping the NEWEST rows: a jump longer than the buffer (a frame
 * after a long stall) would otherwise write rows that the same call immediately overwrites. Empty
 * when nothing new is due, including when the target has not moved past the last row.
 */
export function rowsToWrite(lastRow: number, targetRow: number, capacity: number): number[] {
    const first = Math.max(lastRow + 1, targetRow - capacity + 1);
    const rows: number[] = [];

    for (let row = first; row <= targetRow; row++) rows.push(row);

    return rows;
}

/**
 * Encode a 0–1 spectrum as the bytes of one texture row, in place.
 *
 * Bytes rather than floats because an 8-bit red texture is what every WebGL2 device can filter
 * linearly; a float texture needs an extension phones do not reliably have.
 */
export function encodeRow(spectrum: Float32Array, out: Uint8Array): void {
    for (let band = 0; band < out.length; band++) {
        out[band] = Math.round(Math.min(1, Math.max(0, spectrum[band] ?? 0)) * 255);
    }
}
