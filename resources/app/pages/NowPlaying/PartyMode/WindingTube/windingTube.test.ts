import { describe, expect, it } from "vitest";
import { PATH_FAR, PATH_NEAR, PATH_SAMPLES, pathCentres, pathDepth, pathOffset, windingPath } from "./windingTube";

/*
 * The winding tube's CPU half — the path both tunnels fly along. Arithmetic, so it is pinned here
 * rather than in a browser. What a wrong answer looks like on screen, for each:
 *
 *   - SAMPLES OUT OF ORDER break the shader's near-to-far walk, which is what finds the wall you
 *     actually see on a bend (out of order, the far tube shows through the near one).
 *   - A PATH THAT DOES NOT LEAVE THE CAMERA DEAD AHEAD skews the whole tunnel instead of curving it.
 *   - A PATH TAKEN FROM A WRAPPED TRAVEL snaps back to its first bend once a lap.
 */

/** A fixed roll of the path's phases, so the tests do not depend on Math.random. */
const OFFSETS = [0.3, 1.1, 2.5, 4.0];

/** The path's sampled screen centres at `travel`. */
const centres = (travel: number, offsets = OFFSETS): number[] => {
    const out = new Float32Array(PATH_SAMPLES * 2);
    pathCentres(travel, offsets, out);

    return Array.from(out);
};

describe("the winding path", () => {
    it("samples from just ahead of the camera to its far end, nearest first", () => {
        expect(pathDepth(0)).toBeCloseTo(PATH_NEAR, 10);
        expect(pathDepth(PATH_SAMPLES - 1)).toBeCloseTo(PATH_FAR, 10);
        for (let index = 1; index < PATH_SAMPLES; index++) {
            expect(pathDepth(index)).toBeGreaterThan(pathDepth(index - 1));
        }
    });

    it("leaves the camera dead ahead and bends away only with distance", () => {
        // The path's value AND slope at the camera are taken out, so the near rings sit on the
        // centre of the screen — otherwise the whole tunnel would be skewed, not curved.
        const [nearX, nearY] = pathOffset(42, OFFSETS, 0.01);
        expect(Math.hypot(nearX, nearY)).toBeLessThan(1e-3);

        const [farX, farY] = pathOffset(42, OFFSETS, 7);
        expect(Math.hypot(farX, farY)).toBeGreaterThan(0.1);
    });

    it("moves smoothly however far the flight has come", () => {
        // A tunnel's shader wraps its own travel once a lap; the path is computed from the real
        // one, so it must carry on through any such point as if nothing happened.
        const before = centres(8 * 3 - 0.001);
        const after = centres(8 * 3 + 0.001);

        before.forEach((value, index) => expect(value).toBeCloseTo(after[index]!, 3));
    });

    it("winds a different way for a different roll of the phases", () => {
        expect(centres(10, [0, 0, 0, 0])).not.toEqual(centres(10));
        expect(windingPath(() => 0.5).offsets).not.toEqual(windingPath(() => 0.1).offsets);
    });
});
