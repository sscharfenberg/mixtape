import { describe, expect, it, vi } from "vitest";
import { iconNames, mountApp } from "Testing/mount";
import FactPair from "./FactPair.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * One tile, shared by the facts cards and every detail page's hero — which is the whole
 * reason it was extracted, so the two cannot drift.
 *
 * The thing worth pinning is its ACCESSIBLE NAME when the tile links. The anchor wraps only
 * the VALUE and is stretched over the padding by a `::after`, precisely so a screen reader
 * reads "Luciferian Towers" and not "ALBUM Luciferian Towers". Wrapping the whole tile
 * instead is the obvious simplification, it looks identical, and it quietly turns every
 * link on the page into a label-plus-value mouthful — so the anchor's text content is the
 * assertion that keeps that refactor honest.
 *
 * The stretched hit box, the hover inversion and the `:has()` focus ring are CSS, which
 * this layer does not compile (docs/testing.md). What it can hold still is which ELEMENT
 * the value became, and that is the half a refactor breaks.
 */

/** Mount a tile. */
const tile = (props: Record<string, unknown> = {}) => mountApp(FactPair, { props: { label: "Album", value: "Luciferian Towers", ...props } });

describe("FactPair", () => {
    it("renders a plain tile whose value is not a link when the fact leads nowhere", () => {
        const wrapper = tile();

        expect(wrapper.find(".fact-pair__value").element.tagName).toBe("SPAN");
        expect(wrapper.find("a").exists()).toBe(false);
        expect(wrapper.classes()).not.toContain("fact-pair--link");
    });

    it("turns the value into a link, and names it with the value alone", () => {
        // The label stays outside the anchor. "ALBUM Luciferian Towers" is the regression.
        const wrapper = tile({ href: "/music/albums/album-1" });
        const link = wrapper.find("a");

        expect(link.attributes("href")).toBe("/music/albums/album-1");
        expect(link.text()).toBe("Luciferian Towers");
        expect(wrapper.classes()).toContain("fact-pair--link");
    });

    it("shows an icon for the kind of fact only when given one", () => {
        expect(iconNames(tile())).toStrictEqual([]);
        expect(iconNames(tile({ icon: "album" }))).toStrictEqual(["album"]);
    });

    it("monospaces a value read character by character, and leaves prose alone", () => {
        expect(tile({ value: "/var/media/a.mp3", mono: true }).find(".fact-pair__value").classes()).toContain(
            "fact-pair__value--mono"
        );
        expect(tile().find(".fact-pair__value").classes()).not.toContain("fact-pair__value--mono");
    });

    it("is an <li>, because both of its hosts wrap it in a list", () => {
        expect(tile().element.tagName).toBe("LI");
    });
});
