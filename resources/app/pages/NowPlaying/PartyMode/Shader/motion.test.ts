import { describe, expect, it } from "vitest";
import { ease, wrap } from "./motion";

/*
 * The two pieces of arithmetic every visualiser's state moves by. A wrap that is wrong for negative
 * numbers puts a phase in the wrong place; an ease that depends on the frame rate makes the same
 * picture move at different speeds on different screens.
 */

describe("wrap", () => {
    it("is a modulo that stays positive", () => {
        expect(wrap(9, 8)).toBe(1);
        expect(wrap(-1, 8)).toBe(7);
        expect(wrap(16, 8)).toBe(0);
    });
});

describe("ease", () => {
    it("arrives at the same place whether a second passes in one step or in sixty", () => {
        let stepped = 0;
        for (let frame = 0; frame < 60; frame++) stepped = ease(stepped, 1, 1 / 60, 0.25);

        expect(stepped).toBeCloseTo(ease(0, 1, 1, 0.25), 10);
    });

    it("never overshoots its target", () => {
        expect(ease(0, 1, 100, 0.01)).toBeLessThanOrEqual(1);
        expect(ease(1, 0, 100, 0.01)).toBeGreaterThanOrEqual(0);
    });
});
