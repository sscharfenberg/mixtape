import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import Breadcrumb from "./Breadcrumb.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Breadcrumb is the read half of the useBreadcrumbs singleton — mounted once in
 * FullLayout, fed by whatever page happens to be on screen, with no props between them.
 * These tests drive it the way the app does: write the trail through the composable,
 * then assert what the layout renders.
 *
 * The behaviour that matters: an empty trail renders NOTHING (not an empty nav with a
 * stray home chip), a crumb without an href is the current page and must say so to
 * assistive tech, and a raw `label` beats a `labelKey` because that is what carries a
 * song title the catalog will never contain.
 */

/** Set the trail and mount the component over it. */
const trail = async (crumbs: Parameters<ReturnType<typeof useBreadcrumbs>["setBreadcrumbs"]>[0]) => {
    useBreadcrumbs().setBreadcrumbs(crumbs);
    const wrapper = mountApp(Breadcrumb);
    await nextTick();

    return wrapper;
};

describe("Breadcrumb", () => {
    beforeEach(() => {
        resetInertia();
        useBreadcrumbs().setBreadcrumbs([]);
    });

    it("renders nothing at all for an empty trail", async () => {
        const wrapper = await trail([]);

        // Not even the home chip: the site root IS the home chip.
        expect(wrapper.find("nav").exists()).toBe(false);
        expect(wrapper.html()).toBe("<!--v-if-->");
    });

    it("puts a home link in front of the trail", async () => {
        const wrapper = await trail([{ label: "Songs" }]);

        const home = wrapper.findAll("a")[0];
        expect(home.attributes("href")).toBe("/");
        expect(home.attributes("aria-label")).toBe(translate("breadcrumb.home"));
    });

    it("renders a linked crumb as a link to its href", async () => {
        const wrapper = await trail([
            { labelKey: "header.siteMenu.music", href: "/music" },
            { label: "Paranoid Android" }
        ]);

        const links = wrapper.findAll("a");
        expect(links[1].attributes("href")).toBe("/music");
        expect(links[1].text()).toBe(translate("header.siteMenu.music"));
    });

    it("renders the final crumb as the current page, not a link", async () => {
        const wrapper = await trail([{ labelKey: "header.siteMenu.music", href: "/music" }, { label: "Karma Police" }]);

        const current = wrapper.find("[aria-current='page']");
        expect(current.text()).toBe("Karma Police");
        expect(current.element.tagName).toBe("SPAN");
    });

    it("prefers a raw label over a translation key", async () => {
        // A song title is never in the catalog — raw has to win.
        const wrapper = await trail([{ label: "No Surprises", labelKey: "header.siteMenu.music" }]);

        expect(wrapper.text()).toContain("No Surprises");
        expect(wrapper.text()).not.toContain(translate("header.siteMenu.music"));
    });

    it("translates a labelKey with its interpolation params", async () => {
        // Uses a real catalog entry that takes a parameter, so a renamed key fails here.
        const wrapper = await trail([{ labelKey: "music.widgets.songs" }]);

        expect(wrapper.text()).toContain(translate("music.widgets.songs"));
    });

    it("renders a crumb's icon when it has one, and none when it does not", async () => {
        /*
         * Asserted one at a time on purpose. The trail is a module singleton and the
         * first wrapper stays mounted and reactive, so setting up the second case would
         * re-render the first one out from under the assertion.
         */
        const withIcon = await trail([{ label: "Musik", icon: "music" }]);
        // The home chip always carries one, so compare counts rather than presence.
        expect(withIcon.findAll("svg")).toHaveLength(2);
        withIcon.unmount();

        const withoutIcon = await trail([{ label: "Musik" }]);
        expect(withoutIcon.findAll("svg")).toHaveLength(1);
    });

    it("marks the second-to-last crumb as the parent, for the narrow-screen collapse", async () => {
        // On a narrow screen the whole trail collapses to just this one, flipped —
        // at that width the trail's only job is "go back one level".
        const wrapper = await trail([
            { labelKey: "header.siteMenu.music", href: "/music" },
            { labelKey: "music.widgets.songs", href: "/music/songs" },
            { label: "Let Down" }
        ]);

        const parents = wrapper.findAll(".breadcrumb__item--parent");
        expect(parents).toHaveLength(1);
        expect(parents[0].text()).toBe(translate("music.widgets.songs"));
    });

    it("marks no parent on a top-level page, so the narrow view shows nothing", async () => {
        const wrapper = await trail([{ label: "Musik" }]);

        expect(wrapper.find(".breadcrumb__item--parent").exists()).toBe(false);
    });

    it("wraps each label in its own element so an over-long one can be ellipsised", async () => {
        // text-overflow has nothing to act on when the text is an anonymous flex item.
        const wrapper = await trail([{ label: "Ein sehr langer Songtitel der nicht umbrechen darf" }]);

        expect(wrapper.find(".breadcrumb__label").text()).toBe("Ein sehr langer Songtitel der nicht umbrechen darf");
    });

    it("follows the trail when the page replaces it", async () => {
        const wrapper = await trail([{ label: "Erste Seite" }]);

        useBreadcrumbs().setBreadcrumbs([{ label: "Zweite Seite" }]);
        await nextTick();

        expect(wrapper.text()).toContain("Zweite Seite");
        expect(wrapper.text()).not.toContain("Erste Seite");
    });

    it("disappears again when the trail is cleared on navigation", async () => {
        // main.ts clears it with [] on every Inertia navigation start.
        const wrapper = await trail([{ label: "Vorherige Seite" }]);

        useBreadcrumbs().setBreadcrumbs([]);
        await nextTick();

        expect(wrapper.find("nav").exists()).toBe(false);
    });

    it("labels the nav for assistive tech", async () => {
        const wrapper = await trail([{ label: "Songs" }]);

        expect(wrapper.find("nav").attributes("aria-label")).toBe(translate("breadcrumb.nav"));
    });
});
