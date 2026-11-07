import { describe, expect, it } from "vitest";
import { drawingBufferSize, parseComputedColour } from "./useVisualizer";

/*
 * The two decisions in party mode's host loop that are arithmetic: how many pixels to draw, and
 * how a computed colour becomes a shader uniform. The loop itself — frames, the tap, the plugin —
 * needs a GPU and real audio, and is proven in now-playing.spec.ts instead.
 *
 * Why each matters: a drawing buffer at a phone's native 3× is nine times the work for a picture
 * of soft glow, and a 4K screen is worse; a colour that fails to parse paints the whole tunnel
 * black, which reads as "WebGL is broken" rather than as a typo in a token.
 */

describe("drawingBufferSize", () => {
    it("draws one pixel per CSS pixel, however dense the screen", () => {
        expect(drawingBufferSize(400, 800, 3, 1)).toEqual({ width: 400, height: 800 });
    });

    it("scales a large screen into the pixel budget, keeping its shape", () => {
        const { width, height } = drawingBufferSize(3840, 2160, 1, 1);

        expect(width * height).toBeLessThanOrEqual(1_400_000 * 1.01);
        expect(width / height).toBeCloseTo(3840 / 2160, 2);
    });

    it("applies the quality on top, and never answers zero", () => {
        expect(drawingBufferSize(1000, 500, 1, 0.5)).toEqual({ width: 500, height: 250 });
        expect(drawingBufferSize(0, 0, 1, 1)).toEqual({ width: 1, height: 1 });
    });
});

describe("parseComputedColour", () => {
    it("reads the rgb() and rgba() a browser computes for any colour", () => {
        expect(parseComputedColour("rgb(255, 97, 175)")).toEqual([1, 97 / 255, 175 / 255]);
        expect(parseComputedColour("rgba(0, 0, 0, 0.6)")).toEqual([0, 0, 0]);
        expect(parseComputedColour("rgb(0 161 239)")).toEqual([0, 161 / 255, 239 / 255]);
    });

    it("answers null for anything that is not one", () => {
        expect(parseComputedColour("")).toBeNull();
        expect(parseComputedColour("var(--party-glow)")).toBeNull();
    });
});
