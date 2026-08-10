import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { ANALYSER_DEFAULT_BANDS, resetAudioAnalyserForTests } from "Composables/useAudioAnalyser";
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
 *   - THE BAR COUNT COMES FROM CSS, and both halves of that are worth pinning: what it draws when
 *     the stylesheet names a count, and what it draws when nothing does. The second is not a
 *     hypothetical — it is every run of this file, since happy-dom applies no scoped styles — and
 *     the failure it guards is a row of zero bars where `parseInt("")` was trusted.
 *
 * WHICH count belongs at which width is not asserted anywhere, and cannot usefully be: it is three
 * numbers in a token consumed by a media query, so a test could only restate the token. The
 * staggering is checked in the browser (tests/e2e/app/now-playing.spec.ts pins the count at the
 * Playwright viewport's own breakpoint).
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

/** Answer the component's one computed-style read with a bar count, as a stylesheet would. */
const withDeclaredBars = (bars: string): void => {
    vi.stubGlobal("getComputedStyle", () => ({
        getPropertyValue: (property: string) => (property === "--visualizer-bars" ? bars : "")
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

    it("draws as many bars as the stylesheet asks for", async () => {
        withMotionPreference(true);
        withDeclaredBars("24");

        // The phone count, which is the whole point of the property: the bars get thinner rather
        // than fewer as the row narrows, so the row asks CSS how many it may draw.
        const wrapper = mountApp(Visualizer);

        // The read happens on mount, so the count it finds lands on the NEXT tick. In a browser that
        // tick is a microtask and therefore still before the first paint — nobody sees the default
        // count flash past — but a synchronous assertion here would only ever see the default.
        await nextTick();

        expect(wrapper.findAll(".visualizer__bar")).toHaveLength(24);
    });

    it("falls back to the default count where no stylesheet has answered", () => {
        // Which is this whole suite: happy-dom evaluates no scoped styles, so the property is empty
        // and `parseInt` gives NaN. A row of zero bars is the failure being guarded.
        withMotionPreference(true);

        expect(mountApp(Visualizer).findAll(".visualizer__bar")).toHaveLength(ANALYSER_DEFAULT_BANDS);
    });

    it("stands at a VISIBLE resting height when there is nothing to show", () => {
        withMotionPreference(true);
        const wrapper = mountApp(Visualizer);

        // Not merely non-zero: the row used to rest at 2%, which in a 56px strip is one pixel of
        // grey — "hidden when paused" in the owner's words, and the exact failure the resting height
        // exists to prevent. Pinned as a number because that is the thing that regressed.
        const heights = wrapper.findAll(".visualizer__bar").map(bar => bar.attributes("style"));

        expect(heights.every(style => style?.includes("height: 16%"))).toBe(true);
    });

    it("stays idle — and unrouted — for a reader who asked for less motion", () => {
        // The important half is what does NOT happen: no activation, so useAudioAnalyser never
        // reaches `createMediaElementSource`, and that reader's audio is never routed.
        withMotionPreference(false);
        const wrapper = mountApp(Visualizer);

        expect(wrapper.find(".visualizer--live").exists()).toBe(false);
        expect(wrapper.findAll(".visualizer__bar")).toHaveLength(ANALYSER_DEFAULT_BANDS);
    });

    it("says nothing to a screen reader", () => {
        // Every reading it shows is already on the page as the track's own facts, and a few dozen
        // changing numbers is noise in the most literal sense.
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
