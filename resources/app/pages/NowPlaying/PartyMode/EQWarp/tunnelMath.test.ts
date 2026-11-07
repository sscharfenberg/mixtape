import { describe, expect, it } from "vitest";
import {
    HISTORY_LENGTH,
    PATH_FAR,
    PATH_NEAR,
    PATH_SAMPLES,
    RING_LENGTH,
    SPAWN_DEPTH,
    encodeRow,
    pathCentres,
    pathDepth,
    pathOffset,
    rowsToWrite,
    wrap
} from "./tunnelMath";

/*
 * The warp tunnel's bookkeeping — the parts of it that are arithmetic rather than pixels, and
 * therefore the parts a GPU-less test can actually hold to account. What a wrong answer looks like
 * on screen, for each:
 *
 *   - ROWS SKIPPED leave a dark ring in the wall that flies at you for the rest of the lap;
 *     rows written twice are harmless but a long stall must not write the buffer over itself.
 *   - A WRAP that is wrong for negative numbers puts a row in the wrong place, once.
 *   - A RING LENGTH that does not divide the buffer EXACTLY draws a seam once a lap.
 *   - THE WINDING PATH taken from the wrapped travel (the shader's) instead of the real one would
 *     snap the tunnel straight back to its starting bend once a lap.
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

describe("wrap", () => {
    it("is a modulo that stays positive", () => {
        expect(wrap(9, 8)).toBe(1);
        expect(wrap(-1, 8)).toBe(7);
        expect(wrap(16, 8)).toBe(0);
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
});

describe("encodeRow", () => {
    it("turns 0–1 levels into bytes, clamping anything out of range", () => {
        const out = new Uint8Array(4);
        encodeRow(new Float32Array([0, 0.5, 1, 1.4]), out);

        expect(Array.from(out)).toEqual([0, 128, 255, 255]);
    });
});

describe("the winding path", () => {
    /** The path's sampled screen centres at `travel`, with the given per-component offsets. */
    const centres = (travel: number, offsets = [0.3, 1.1, 2.5, 4.0]): number[] => {
        const out = new Float32Array(PATH_SAMPLES * 2);
        pathCentres(travel, offsets, out);

        return Array.from(out);
    };

    it("samples from just ahead of the camera to past the spawn fade, nearest first", () => {
        expect(pathDepth(0)).toBeCloseTo(PATH_NEAR, 10);
        expect(pathDepth(PATH_SAMPLES - 1)).toBeCloseTo(PATH_FAR, 10);
        expect(PATH_FAR).toBeGreaterThan(SPAWN_DEPTH);
        for (let index = 1; index < PATH_SAMPLES; index++) {
            expect(pathDepth(index)).toBeGreaterThan(pathDepth(index - 1));
        }
    });

    it("leaves the camera dead ahead and bends away only with distance", () => {
        // The path's value AND slope at the camera are taken out, so the near rings sit on the
        // centre of the screen — otherwise the whole tunnel would be skewed, not curved.
        const [nearX, nearY] = pathOffset(42, [0.3, 1.1, 2.5, 4.0], 0.01);
        expect(Math.hypot(nearX, nearY)).toBeLessThan(1e-3);

        const [farX, farY] = pathOffset(42, [0.3, 1.1, 2.5, 4.0], SPAWN_DEPTH);
        expect(Math.hypot(farX, farY)).toBeGreaterThan(0.1);
    });

    it("moves smoothly through the point where the walls' coordinates wrap", () => {
        // A lap of the history buffer is where the shader's own travel jumps back to zero; the
        // path must carry on as if nothing happened there.
        const before = centres(HISTORY_LENGTH * 3 - 0.001);
        const after = centres(HISTORY_LENGTH * 3 + 0.001);

        before.forEach((value, index) => expect(value).toBeCloseTo(after[index]!, 3));
    });

    it("winds a different way for a different roll of the offsets", () => {
        expect(centres(10, [0, 0, 0, 0])).not.toEqual(centres(10));
    });
});
