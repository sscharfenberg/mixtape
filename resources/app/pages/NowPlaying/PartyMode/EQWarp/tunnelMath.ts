/******************************************************************************
 * tunnelMath
 * The EQ warp's own geometry — the spectrum history its walls are made of — as numbers the shader
 * and the renderer both read, and the two pieces of bookkeeping worth testing without a GPU. The
 * tube it is drawn in, and its winding, are WindingTube's.
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
 * Must stay under HISTORY_LENGTH, or the buffer would overwrite rows that are still on screen, and
 * under the winding tube's PATH_FAR, past which no wall is found.
 */
export const SPAWN_DEPTH = 7;

/**
 * The length of one ring of cells along the tube. It must divide HISTORY_LENGTH EXACTLY IN BINARY,
 * so the grid wraps seamlessly in a float32 shader too: 0.25 does, where 0.2 divides 8 only in
 * decimal and would leave a one-frame seam on every lap.
 */
export const RING_LENGTH = 0.25;

/** Cells around the tube — 32 per side, mirrored, so each side reads as one EQ of half the bands' count. */
export const SEGMENTS = 64;

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
