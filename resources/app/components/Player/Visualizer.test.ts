import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ANALYSER_BANDS, resetAudioAnalyserForTests } from "Composables/useAudioAnalyser";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import Visualizer from "./Visualizer.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The EQ, at the layer that can actually answer something.
 *
 * WHAT THIS CANNOT TEST, and it is most of the component: whether the bars MOVE. That needs a
 * decoding audio element and a live AudioContext, neither of which happy-dom has — and even a
 * real browser will not give it, because Playwright launches Chromium muted and the analyser then
 * reads zeros however loudly a file is playing (measured 2026-08-09: `live` true, every bar at the
 * 2% baseline, with the audio clock advancing normally). So a browser test can prove the graph is
 * WIRED and never that it produces a spectrum; the gradient was checked by forcing heights from a
 * stylesheet and looking.
 *
 * What is left is what this file covers, and all three are ways the row could be quietly wrong:
 *
 *   - IT NEVER COLLAPSES. Idle bars sit on a baseline rather than at zero height, because an EQ of
 *     no height is an invisible gap that reads as something failing to load.
 *   - REDUCED MOTION MEANS NOT ASKING. The animation here is JavaScript writing a height per
 *     frame, so no media query can stop it — the component has to decline to activate, which also
 *     means such a reader's audio is never routed through an AudioContext at all.
 *   - THE BAR COUNT IS THE ANALYSER'S. Two constants that had to agree would eventually not, and
 *     the symptom would be dead bars at one end.
 */

/** Answer `matchMedia` for the motion query, and nothing else. */
const withMotionPreference = (allowed: boolean): void => {
    vi.stubGlobal("matchMedia", (query: string) => ({
        matches: query.includes("no-preference") ? allowed : !allowed,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn()
    }));
};

describe("Visualizer", () => {
    beforeEach(() => {
        resetInertia();
        resetAudioAnalyserForTests();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("draws one bar per band the analyser produces", () => {
        withMotionPreference(true);

        expect(mountApp(Visualizer).findAll(".visualizer__bar")).toHaveLength(ANALYSER_BANDS);
    });

    it("sits on a baseline rather than collapsing when there is nothing to show", () => {
        withMotionPreference(true);
        const wrapper = mountApp(Visualizer);

        // Every bar has a height, and none of them is zero — see the banner.
        const heights = wrapper.findAll(".visualizer__bar").map(bar => bar.attributes("style"));

        expect(heights.every(style => style?.includes("height: 2%"))).toBe(true);
    });

    it("stays idle — and unrouted — for a reader who asked for less motion", () => {
        // The important half is what does NOT happen: no activation, so useAudioAnalyser never
        // reaches `createMediaElementSource`, and that reader's audio is never routed.
        withMotionPreference(false);
        const wrapper = mountApp(Visualizer);

        expect(wrapper.find(".visualizer--live").exists()).toBe(false);
        expect(wrapper.findAll(".visualizer__bar")).toHaveLength(ANALYSER_BANDS);
    });

    it("says nothing to a screen reader", () => {
        // Every reading it shows is already on the page as the track's own facts, and 48 changing
        // numbers is noise in the most literal sense.
        withMotionPreference(true);

        expect(mountApp(Visualizer).find(".visualizer").attributes("aria-hidden")).toBe("true");
    });

    it("does not throw where the browser has no Web Audio at all", () => {
        // happy-dom has no AudioContext, which is exactly the case the composable guards: the bars
        // fall back to their baseline and playback is untouched, rather than the page failing to
        // mount. A real browser without it behaves the same way.
        withMotionPreference(true);

        expect(() => mountApp(Visualizer)).not.toThrow();
    });
});
