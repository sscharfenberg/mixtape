import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import WidgetList from "./WidgetList.vue";
import type { WidgetListItem } from "./WidgetList.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The shared body of all four music widgets, so a mistake here is a mistake on every card
 * of the Music page at once.
 *
 * The pips carry the load: a pip shows an ICON and a value and never a written label,
 * which only works because the label survives in the tooltip. Drop that tip and the page
 * becomes a grid of unexplained glyphs — so the tip's presence and its "Label: value"
 * shape are asserted rather than assumed.
 */

/** An item with sensible defaults; tests override only what they are about. */
const item = (overrides: Partial<WidgetListItem> = {}): WidgetListItem => ({
    id: "entry-1",
    name: "OK Computer",
    href: "/music/albums/entry-1",
    pips: [{ icon: "artist", value: "Radiohead", label: "Künstler" }],
    ...overrides
});

/** Mount the list over a set of items. */
const list = (items: WidgetListItem[], emptyText?: string) =>
    mountApp(WidgetList, { props: { items, emptyText } });

describe("WidgetList", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("renders each entry as a link to the thing it names", () => {
        const wrapper = list([item()]);
        const link = wrapper.find(".widget-list__item");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/albums/entry-1");
        expect(wrapper.find(".widget-list__name").text()).toBe("OK Computer");
    });

    it("renders one pip per fact, showing the value and not the label", () => {
        const wrapper = list([
            item({
                pips: [
                    { icon: "album", value: "12", label: "Anzahl Alben" },
                    { icon: "song", value: "406", label: "Anzahl Songs" }
                ]
            })
        ]);

        const pips = wrapper.findAll(".widget-list__pip");
        expect(pips).toHaveLength(2);
        expect(pips[0].text()).toBe("12");
        // The written label must NOT be on screen — that is the whole point of a pip.
        expect(wrapper.text()).not.toContain("Anzahl Alben");
    });

    it("gives each pip a tooltip naming the fact and its value", () => {
        /*
         * The accessibility half of the design: without this the icon is the only thing
         * saying what the number means. v-tooltip sets the hint on the element, so the
         * assertion is on the pip having been given one.
         */
        const wrapper = list([item({ pips: [{ icon: "album", value: "12", label: "Anzahl Alben" }] })]);
        const pip = wrapper.find(".widget-list__pip");

        pip.trigger("focusin");

        expect(pip.attributes("style")).toContain("anchor-name");
    });

    it("shows each pip's icon", () => {
        const wrapper = list([
            item({
                pips: [
                    { icon: "album", value: "12", label: "Alben" },
                    { icon: "duration", value: "1:04:22", label: "Dauer" }
                ]
            })
        ]);

        const symbols = wrapper.findAll(".widget-list__pip use").map(node => node.attributes("href"));
        expect(symbols).toStrictEqual(["#album", "#duration"]);
    });

    it("renders an entry with no pips at all", () => {
        // An album with neither an artist nor a year still has to render its name.
        const wrapper = list([item({ pips: [] })]);

        expect(wrapper.find(".widget-list__name").text()).toBe("OK Computer");
        expect(wrapper.find(".widget-list__pips").exists()).toBe(false);
    });

    it("renders one row per entry, in the order given", () => {
        const wrapper = list([
            item({ id: "a", name: "First" }),
            item({ id: "b", name: "Second" })
        ]);

        expect(wrapper.findAll(".widget-list__name").map(node => node.text())).toStrictEqual(["First", "Second"]);
    });

    it("falls back to the generic empty line when there is nothing to show", () => {
        const wrapper = list([]);

        expect(wrapper.find("ul").exists()).toBe(false);
        expect(wrapper.text()).toBe(translate("music.empty"));
    });

    it("uses the caller's own empty line when given one", () => {
        // Songs' "popular" set says "not enough data" rather than "nothing here".
        const wrapper = list([], translate("music.notEnoughData"));

        expect(wrapper.text()).toBe(translate("music.notEnoughData"));
    });
});
