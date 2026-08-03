import { describe, expect, it } from "vitest";
import { mountApp, translate } from "Testing/mount";
import PlayerTimeline from "./PlayerTimeline.vue";

/*
 * The timeline turns three numbers into geometry, and every one of those conversions
 * has a way of being wrong that looks fine on a static screenshot.
 *
 * THE BUFFER INDICATOR is the reason most of this file exists. It answers "would
 * dragging ahead cost me a download?", which over a home uplink is a real question —
 * so it has to be drawn from the browser's actual ranges rather than from a single
 * "buffered up to here" number. After a seek past the buffer there are genuinely two
 * stretches with a hole between them, and a component that painted only the first
 * would claim the hole is held.
 *
 * THE SCRUB is committed on release, not on every pixel of the drag. That is not a
 * detail: seeking per pixel means a Range request per pixel against a server reading
 * a 96GB collection off a spinning disk. So `input` must move the fill and emit
 * nothing, and `change` must emit exactly once.
 *
 * What is NOT here: how any of it looks. Vitest compiles no CSS, so the layers'
 * stacking and the invisible-input-over-a-thin-rail trick are Playwright's to check.
 * These tests assert the numbers that CSS is handed.
 */

/** The timeline over one track, with sensible defaults each test overrides. */
const timeline = (props: Partial<InstanceType<typeof PlayerTimeline>["$props"]> = {}) =>
    mountApp(PlayerTimeline, {
        props: { currentTime: 0, duration: 200, buffered: [], ...props }
    });

/** The inline width of the played fill, e.g. "25%". */
const playedWidth = (wrapper: ReturnType<typeof timeline>): string | undefined =>
    wrapper.find(".player-timeline__played").attributes("style")?.match(/width:\s*([^;]+)/u)?.[1];

/** Every buffer segment's left/width pair, in DOM order. */
const segments = (wrapper: ReturnType<typeof timeline>): string[] =>
    wrapper.findAll(".player-timeline__buffer").map(node => node.attributes("style") ?? "");

/**
 * Drag the thumb to `seconds` — the value plus the `input` event, as a browser sends it.
 *
 * Written out rather than using `setValue`, which writes the value onto a range input
 * without dispatching anything, so the handler under test never ran and the assertion
 * passed against an unchanged fill.
 */
const dragTo = async (wrapper: ReturnType<typeof timeline>, seconds: number): Promise<void> => {
    const input = wrapper.find("input");
    (input.element as HTMLInputElement).value = String(seconds);
    await input.trigger("input");
};

describe("PlayerTimeline", () => {
    describe("the played fill", () => {
        it("fills in proportion to the position", () => {
            const wrapper = timeline({ currentTime: 50, duration: 200 });

            expect(playedWidth(wrapper)).toBe("25%");
        });

        it("draws nothing at the start of a track", () => {
            const wrapper = timeline({ currentTime: 0 });

            expect(playedWidth(wrapper)).toBe("0%");
        });

        it("stops at the end rather than overshooting the rail", () => {
            // The two numbers come from different places — the element's real playhead
            // and the database's measurement of the file — so a file whose tags disagree
            // with its bytes really can report a position past its own duration.
            const wrapper = timeline({ currentTime: 260, duration: 200 });

            expect(playedWidth(wrapper)).toBe("100%");
        });

        it("collapses to nothing rather than NaN when the length is unknown", () => {
            // A NaN divisor makes every width in the component "NaN%", which browsers
            // ignore — leaving a timeline that silently never moves.
            const wrapper = timeline({ currentTime: 30, duration: 0 });

            expect(playedWidth(wrapper)).toBe("0%");
        });
    });

    describe("the buffer indicator", () => {
        it("draws one segment per stretch the browser holds", () => {
            const wrapper = timeline({
                duration: 200,
                buffered: [
                    { start: 0, end: 50 },
                    { start: 100, end: 150 }
                ]
            });

            // The hole between them is the whole point: a single "buffered to 150s" bar
            // would claim the middle 50 seconds are downloaded when they are not.
            expect(segments(wrapper)).toHaveLength(2);
            expect(segments(wrapper)[0]).toContain("left: 0%");
            expect(segments(wrapper)[0]).toContain("width: 25%");
            expect(segments(wrapper)[1]).toContain("left: 50%");
            expect(segments(wrapper)[1]).toContain("width: 25%");
        });

        it("draws nothing before anything has arrived", () => {
            const wrapper = timeline({ buffered: [] });

            expect(segments(wrapper)).toHaveLength(0);
        });

        it("clamps a range that runs past the claimed duration", () => {
            // A buffered range covers the whole FILE; the duration is what getID3 measured.
            // The two can disagree, and the rail is only ever 100% wide.
            const wrapper = timeline({ duration: 100, buffered: [{ start: 0, end: 140 }] });

            expect(segments(wrapper)[0]).toContain("width: 100%");
        });
    });

    describe("the clock readings", () => {
        it("shows the position and the total as clocks, not seconds", () => {
            const wrapper = timeline({ currentTime: 83, duration: 383 });

            const readings = wrapper.findAll(".player-timeline__time").map(node => node.text());
            expect(readings).toStrictEqual(["1:23", "6:23"]);
        });

        it("shows a placeholder total for a file that carried no duration", () => {
            // Better than "0:00", which would read as a track of no length rather than
            // one whose length nobody knows.
            const wrapper = timeline({ duration: 0 });

            expect(wrapper.findAll(".player-timeline__time")[1].text()).toBe("–:––");
        });
    });

    describe("scrubbing", () => {
        it("emits the seek on release, and not while dragging", async () => {
            const wrapper = timeline({ currentTime: 10, duration: 200 });

            await dragTo(wrapper, 120);

            // The fill has to follow the thumb, or the control feels dead…
            expect(playedWidth(wrapper)).toBe("60%");
            // …but nothing may be seeked yet, or a drag is one Range request per pixel.
            expect(wrapper.emitted("seek")).toBeUndefined();

            await wrapper.find("input").trigger("change");

            expect(wrapper.emitted("seek")).toStrictEqual([[120]]);
        });

        it("hands the position back to the element once the drag is over", async () => {
            const wrapper = timeline({ currentTime: 10, duration: 200 });

            await dragTo(wrapper, 120);
            await wrapper.find("input").trigger("change");
            // The parent seeks and the element reports back; the local drag value must be
            // gone by then, or the fill would be stuck where the thumb was let go.
            await wrapper.setProps({ currentTime: 120.4 });

            expect(playedWidth(wrapper)).toBe(`${(120.4 / 200) * 100}%`);
        });

        it("is disabled for a track whose length nobody knows", () => {
            // Every position on the rail would be meaningless, so there is nothing to
            // offer — rather than a thumb that computes a seek from a NaN.
            const wrapper = timeline({ duration: 0 });

            expect(wrapper.find("input").attributes("disabled")).toBeDefined();
        });

        it("announces the position as a clock rather than as a number of seconds", () => {
            // A range input announces its raw value; "83" is not how anyone reads a
            // position in a song.
            const wrapper = timeline({ currentTime: 83, duration: 383 });
            const input = wrapper.find("input");

            expect(input.attributes("aria-label")).toBe(translate("player.bar.seek"));
            expect(input.attributes("aria-valuetext")).toBe("1:23");
        });

        it("spans the whole track, so a seek can reach the end of it", () => {
            const wrapper = timeline({ duration: 383 });

            expect(wrapper.find("input").attributes("max")).toBe("383");
        });
    });
});
