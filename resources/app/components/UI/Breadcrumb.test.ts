import { beforeEach, describe, expect, it, vi } from "vitest";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import Breadcrumb from "./Breadcrumb.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Breadcrumb is the read half of the trail: mounted once in FullLayout, handed whatever
 * the current page declared through useBreadcrumbs — which travels as an Inertia layout
 * prop and arrives here as a plain `crumbs` prop. These tests drive it the way the
 * layout does, by passing that prop.
 *
 * The behaviour that matters: an empty trail renders NOTHING (not an empty nav with a
 * stray home chip), a crumb without an href is the current page and must say so to
 * assistive tech, and a raw `label` beats a `labelKey` because that is what carries a
 * song title the catalog will never contain.
 */

/** Mount the component over a trail, exactly as FullLayout hands one down. */
const trail = (crumbs: BreadcrumbItem[]) => mountApp(Breadcrumb, { props: { crumbs } });

describe("Breadcrumb", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("renders nothing at all for an empty trail", () => {
        const wrapper = trail([]);

        // Not even the home chip: the site root IS the home chip.
        expect(wrapper.find("nav").exists()).toBe(false);
        expect(wrapper.html()).toBe("<!--v-if-->");
    });

    it("puts a home link in front of the trail", () => {
        const wrapper = trail([{ label: "Songs" }]);

        const home = wrapper.findAll("a")[0];
        expect(home.attributes("href")).toBe("/");
        expect(home.attributes("aria-label")).toBe(translate("breadcrumb.home"));
    });

    it("renders a linked crumb as a link to its href", () => {
        const wrapper = trail([{ labelKey: "header.siteMenu.music", href: "/music" }, { label: "Paranoid Android" }]);

        const links = wrapper.findAll("a");
        expect(links[1].attributes("href")).toBe("/music");
        expect(links[1].text()).toBe(translate("header.siteMenu.music"));
    });

    it("renders the final crumb as the current page, not a link", () => {
        const wrapper = trail([{ labelKey: "header.siteMenu.music", href: "/music" }, { label: "Karma Police" }]);

        const current = wrapper.find("[aria-current='page']");
        expect(current.text()).toBe("Karma Police");
        expect(current.element.tagName).toBe("SPAN");
    });

    it("prefers a raw label over a translation key", () => {
        // A song title is never in the catalog — raw has to win.
        const wrapper = trail([{ label: "No Surprises", labelKey: "header.siteMenu.music" }]);

        expect(wrapper.text()).toContain("No Surprises");
        expect(wrapper.text()).not.toContain(translate("header.siteMenu.music"));
    });

    it("translates a labelKey with its interpolation params", () => {
        // Uses a real catalog entry, so a renamed key fails here.
        const wrapper = trail([{ labelKey: "music.widgets.songs" }]);

        expect(wrapper.text()).toContain(translate("music.widgets.songs"));
    });

    it("renders a crumb's icon when it has one, and none when it does not", () => {
        // The home chip always carries one, so compare counts rather than presence.
        expect(trail([{ label: "Musik", icon: "music" }]).findAll("svg")).toHaveLength(2);
        expect(trail([{ label: "Musik" }]).findAll("svg")).toHaveLength(1);
    });

    it("marks the second-to-last crumb as the parent, for the narrow-screen collapse", () => {
        // On a narrow screen the whole trail collapses to just this one, flipped —
        // at that width the trail's only job is "go back one level".
        const wrapper = trail([
            { labelKey: "header.siteMenu.music", href: "/music" },
            { labelKey: "music.widgets.songs", href: "/music/songs" },
            { label: "Let Down" }
        ]);

        const parents = wrapper.findAll(".breadcrumb__item--parent");
        expect(parents).toHaveLength(1);
        expect(parents[0].text()).toBe(translate("music.widgets.songs"));
    });

    it("marks no parent on a top-level page, so the narrow view shows nothing", () => {
        const wrapper = trail([{ label: "Musik" }]);

        expect(wrapper.find(".breadcrumb__item--parent").exists()).toBe(false);
    });

    it("wraps each label in its own element so an over-long one can be ellipsised", () => {
        // text-overflow has nothing to act on when the text is an anonymous flex item.
        const wrapper = trail([{ label: "Ein sehr langer Songtitel der nicht umbrechen darf" }]);

        expect(wrapper.find(".breadcrumb__label").text()).toBe("Ein sehr langer Songtitel der nicht umbrechen darf");
    });

    it("swaps to the incoming page's trail without an intermediate empty state", async () => {
        /*
         * The whole point of moving the trail onto layout props. Inertia replaces them at
         * the component swap, so the outgoing trail is overwritten in one step — it is
         * never emptied first, which is what used to unmount the <nav> mid-navigation and
         * make the page jump. One prop change, one render, no gap.
         */
        const wrapper = trail([{ label: "Erste Seite" }]);

        await wrapper.setProps({ crumbs: [{ label: "Zweite Seite" }] });

        expect(wrapper.find("nav").exists()).toBe(true);
        expect(wrapper.text()).toContain("Zweite Seite");
        expect(wrapper.text()).not.toContain("Erste Seite");
    });

    it("disappears when the incoming page declares no trail", async () => {
        // A page that sets no crumbs leaves the layout prop unset — FullLayout passes [].
        const wrapper = trail([{ label: "Vorherige Seite" }]);

        await wrapper.setProps({ crumbs: [] });

        expect(wrapper.find("nav").exists()).toBe(false);
    });

    it("labels the nav for assistive tech", () => {
        const wrapper = trail([{ label: "Songs" }]);

        expect(wrapper.find("nav").attributes("aria-label")).toBe(translate("breadcrumb.nav"));
    });
});
