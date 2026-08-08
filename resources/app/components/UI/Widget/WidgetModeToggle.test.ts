import { describe, expect, it, vi } from "vitest";
import { iconNames, mountApp, translate } from "Testing/mount";
import type { WidgetMode } from "Types/music";
import WidgetModeToggle from "./WidgetModeToggle.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The segmented pill in a music widget's title strip. Icons and nothing else, which is what
 * makes its two mappings worth pinning:
 *
 *   - MODE → ICON is a single table so recent/hot/shuffle mean the same thing on all four
 *     browse widgets. Inline the glyphs at each call site instead and they drift one widget
 *     at a time, which nobody notices because each card looks fine on its own.
 *   - "POPULAR" MEANS TWO DIFFERENT THINGS. Songs rank by play count, artists and genres by
 *     total playing time, so the one segment needs two explanations and `popularBy` picks
 *     between them. Getting it wrong tells a reader the artists card is sorted by plays,
 *     which is a plausible sentence about a number that means something else.
 *
 * The ids are namespaced by `name` because four of these live on the Music page at once:
 * shared ids would make the radios one group, so choosing a mode on the albums card would
 * clear the songs card's. Nothing about that is visible until the second toggle appears.
 *
 * Selection itself is native radio behaviour — the arrow keys move it because the inputs are
 * visually hidden rather than `display: none`, which is a browser fact and belongs to
 * Playwright, not to a fake.
 */

const MODES: WidgetMode[] = ["latest", "popular", "random"];

/** Mount a toggle over the three modes, `latest` selected. */
const toggle = (props: Record<string, unknown> = {}) =>
    mountApp(WidgetModeToggle, { props: { name: "albums", modes: MODES, modelValue: "latest", ...props } });

/** The hint text of each segment, in order. */
const hints = (wrapper: ReturnType<typeof toggle>): string[] =>
    wrapper.findAllComponents({ name: "Tooltip" }).map(tip => tip.props("text") as string);

describe("WidgetModeToggle", () => {
    it("renders one segment per mode the widget supports, in the order it named them", () => {
        const wrapper = toggle({ modes: ["latest", "random"] as WidgetMode[] });

        expect(wrapper.findAll("input[type='radio']")).toHaveLength(2);
        expect(iconNames(wrapper)).toStrictEqual(["recent", "shuffle"]);
    });

    it("keeps one glyph per mode across every widget that shows it", () => {
        expect(iconNames(toggle())).toStrictEqual(["recent", "hot", "shuffle"]);
    });

    it("checks the bound mode and reports a change through v-model", async () => {
        const wrapper = toggle();

        expect((wrapper.find("#albums-latest").element as HTMLInputElement).checked).toBe(true);

        await wrapper.find("#albums-random").setValue();

        expect(wrapper.emitted("update:modelValue")).toStrictEqual([["random"]]);
    });

    it("namespaces its ids, so four toggles on one page are four groups and not one", () => {
        const songs = toggle({ name: "songs" });

        expect(songs.findAll("input").map(input => input.attributes("id"))).toStrictEqual([
            "songs-latest",
            "songs-popular",
            "songs-random"
        ]);
        expect(songs.findAll("input").every(input => input.attributes("name") === "songs")).toBe(true);
        // …and the label points at its own input, which is what makes the segment clickable.
        expect(songs.findAll("label").map(label => label.attributes("for"))).toStrictEqual([
            "songs-latest",
            "songs-popular",
            "songs-random"
        ]);
    });

    it("says popular means most-played, and names the second sort key where there is one", () => {
        // Both variants describe the READER'S listening — the taxonomies just add a
        // tie-break so their cards stay populated before much has been played.
        expect(hints(toggle())[1]).toBe(translate("music.mode.tip.popular_plays"));
        expect(hints(toggle({ popularBy: "playsThenDuration" }))[1]).toBe(
            translate("music.mode.tip.popular_playsThenDuration")
        );
    });

    it("explains every mode, since the icons carry no words", () => {
        expect(hints(toggle())).toStrictEqual([
            translate("music.mode.tip.latest"),
            translate("music.mode.tip.popular_plays"),
            translate("music.mode.tip.random")
        ]);
    });

    it("names the group and each segment for a screen reader", () => {
        const wrapper = toggle();

        expect(wrapper.find("[role='radiogroup']").attributes("aria-label")).toBe(translate("music.mode.label"));
        expect(wrapper.findAll("label").map(label => label.attributes("aria-label"))).toStrictEqual([
            translate("music.mode.latest"),
            translate("music.mode.popular"),
            translate("music.mode.random")
        ]);
    });
});
