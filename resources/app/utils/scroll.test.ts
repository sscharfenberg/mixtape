import { afterEach, describe, expect, it, vi } from "vitest";
import { scrollIntoViewTop } from "Utils/scroll";

/*
 * scrollIntoViewTop is three lines, but one of them enforces a project-wide rule:
 * motion is opt-in (CLAUDE.md → Motion), so a smooth scroll must not happen unless
 * the user has expressed no preference against it. That is a real accessibility
 * commitment and nothing else in the codebase re-checks it, so it gets a test.
 *
 * happy-dom has no layout and no matchMedia implementation worth trusting here, so
 * the media query is stubbed — but note what is actually asserted: the ARGUMENT the
 * component passes to the DOM, not that the page scrolled. Whether the browser then
 * animates is the browser's business, and Playwright's.
 */

/** Point window.matchMedia at a fixed answer for the reduced-motion query. */
const stubMotionPreference = (noPreference: boolean): void => {
    vi.stubGlobal(
        "matchMedia",
        vi.fn((query: string) => ({
            matches: query.includes("no-preference") ? noPreference : !noPreference,
            media: query
        }))
    );
};

describe("scrollIntoViewTop", () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("scrolls the element's top into view", () => {
        stubMotionPreference(true);
        const element = document.createElement("div");
        element.scrollIntoView = vi.fn();

        scrollIntoViewTop(element);

        expect(element.scrollIntoView).toHaveBeenCalledExactlyOnceWith({
            block: "start",
            behavior: "smooth"
        });
    });

    it("jumps instead of animating when the user asked to reduce motion", () => {
        stubMotionPreference(false);
        const element = document.createElement("div");
        element.scrollIntoView = vi.fn();

        scrollIntoViewTop(element);

        expect(element.scrollIntoView).toHaveBeenCalledExactlyOnceWith({
            block: "start",
            behavior: "auto"
        });
    });

    it("reads the preference per call, so flipping the OS setting needs no reload", () => {
        const element = document.createElement("div");
        element.scrollIntoView = vi.fn();

        stubMotionPreference(true);
        scrollIntoViewTop(element);
        stubMotionPreference(false);
        scrollIntoViewTop(element);

        expect(element.scrollIntoView).toHaveBeenNthCalledWith(1, { block: "start", behavior: "smooth" });
        expect(element.scrollIntoView).toHaveBeenNthCalledWith(2, { block: "start", behavior: "auto" });
    });

    it("is a no-op for a missing element, so callers need not guard a template ref", () => {
        stubMotionPreference(true);

        expect(() => scrollIntoViewTop(null)).not.toThrow();
        expect(() => scrollIntoViewTop(undefined)).not.toThrow();
    });
});
