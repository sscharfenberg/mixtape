import { describe, expect, it } from "vitest";
import { PATH_FAR } from "../WindingTube/windingTube";
import { HISTORY_LENGTH, RING_LENGTH, SPAWN_DEPTH, encodeRow, rowsToWrite } from "./tunnelMath";

/*
 * The EQ warp's bookkeeping — the parts of it that are arithmetic rather than pixels, and
 * therefore the parts a GPU-less test can actually hold to account. What a wrong answer looks like
 * on screen, for each:
 *
 *   - ROWS SKIPPED leave a dark ring in the wall that flies at you for the rest of the lap;
 *     rows written twice are harmless but a long stall must not write the buffer over itself.
 *   - A RING LENGTH that does not divide the buffer EXACTLY draws a seam once a lap.
 *   - A SPAWN POINT past the winding tube's last ring writes walls no pixel can ever find.
 */

describe("rowsToWrite", () => {
    it("writes every row since the last one, up to and including the target", () => {
        expect(rowsToWrite(10, 13, 256)).toEqual([11, 12, 13]);
    });

    it("writes nothing when the flight has not reached a new row", () => {
        expect(rowsToWrite(10, 10, 256)).toEqual([]);
        expect(rowsToWrite(10, 9, 256)).toEqual([]);
    });

    it("keeps only the newest rows after a jump longer than the buffer", () => {
        const rows = rowsToWrite(0, 1000, 256);

        expect(rows).toHaveLength(256);
        expect(rows[0]).toBe(745);
        expect(rows[rows.length - 1]).toBe(1000);
    });
});

describe("the geometry", () => {
    it("divides the buffer into whole rings in binary, so the grid has no seam", () => {
        // Exactness in float32 is what matters to the shader; Math.fround is that precision.
        const rings = Math.fround(HISTORY_LENGTH) / Math.fround(RING_LENGTH);

        expect(Number.isInteger(rings)).toBe(true);
    });

    it("spawns new rows inside the buffer, so nothing on screen is overwritten", () => {
        expect(SPAWN_DEPTH).toBeLessThan(HISTORY_LENGTH);
    });

    it("spawns them inside the winding tube's sampled length, where a pixel can still find them", () => {
        expect(SPAWN_DEPTH).toBeLessThan(PATH_FAR);
    });
});

describe("encodeRow", () => {
    it("turns 0–1 levels into bytes, clamping anything out of range", () => {
        const out = new Uint8Array(4);
        encodeRow(new Float32Array([0, 0.5, 1, 1.4]), out);

        expect(Array.from(out)).toEqual([0, 128, 255, 255]);
    });
});
