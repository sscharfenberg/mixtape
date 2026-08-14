import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import SiteMenu from "./SiteMenu.vue";
import SiteMenuLinks from "./SiteMenuLinks.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's area navigation, in two presentations that swap by viewport width. Which one
 * is visible is a media query, so that half is CSS and belongs to a browser — what is
 * testable here is what the markup commits to.
 *
 * BOTH FORMS ARE ALWAYS RENDERED, one hidden by CSS. That is worth pinning because the
 * obvious "optimisation" is to pick one in JS off a width, which trades a free CSS swap for
 * a resize listener, a hydration mismatch, and a menu that is briefly wrong on load.
 *
 * THE MENU IS GATED ON THE SESSION. Every area behind it sits behind `auth` middleware, so
 * a guest offered these links gets a redirect to the login form. The gate is one `v-if`, and
 * a guest is the state nobody developing the app is ever in.
 *
 * THE ACTIVE-AREA TEST IS PREFIX-BASED, and its edge cases are the whole reason it is not
 * `startsWith(href)`:
 *   - a DESCENDANT must light its parent up. Standing on /music/albums/album-1, the "Musik"
 *     link is the area the reader is in; an equality check leaves the header looking as if
 *     they were nowhere.
 *   - a SIBLING WHOSE PATH MERELY STARTS THE SAME must not. A bare `startsWith("/music")`
 *     also matches "/musicians" — a wrong highlight that is invisible until such a route
 *     exists, and then unexplainable.
 *   - a QUERY STRING must not defeat it. Sorting a table puts `?sort=…` in the URL, and a
 *     comparison against the whole URL would drop the highlight the moment anyone sorts.
 */

/** Mount the whole menu for a guest or a signed-in reader. */
/**
 * A library holding both kinds, so every area is offered.
 *
 * The areas are conditional — the header offers Music only to a library
 * with music in it — so a menu test that says nothing about the library gets an empty one
 * and asserts against no links at all. useSiteAreas' own spec is where the conditions are
 * covered; here they are simply satisfied.
 */
const FULL_LIBRARY = { music: true, audiobook: true };

const menu = (user: unknown = { name: "Ashaltiriak" }) => {
    setPage({ props: { auth: { user }, library: FULL_LIBRARY } });

    return mountApp(SiteMenu);
};

/** Mount just the desktop links, standing at `url`. */
const links = (url: string) => {
    setPage({ props: { auth: { user: { name: "Ashaltiriak" } }, library: FULL_LIBRARY }, url });

    return mountApp(SiteMenuLinks);
};

/** The hrefs of the links currently marked as the active area. */
const activeHrefs = (wrapper: ReturnType<typeof links>): string[] =>
    wrapper.findAll(".site-menu-links__link--active").map(link => link.attributes("href")!);

describe("SiteMenu", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("shows nothing to a guest, since every area behind it needs a session", () => {
        expect(menu(null).find(".site-menu").exists()).toBe(false);
    });

    it("renders both presentations for a signed-in reader and lets CSS pick", () => {
        // Not a width read in JS: that costs a resize listener and a wrong first frame.
        const wrapper = menu();

        expect(wrapper.findComponent({ name: "SiteMenuLinks" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "SiteMenuPopover" }).exists()).toBe(true);
    });
});

describe("SiteMenuLinks", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("offers one link per area, and names the nav landmark", () => {
        const wrapper = links("/");

        expect(wrapper.findAll(".site-menu-links__link").length).toBeGreaterThan(0);
        expect(wrapper.find("nav").attributes("aria-label")).toBe(translate("header.siteMenu.nav"));
    });

    it("lights up the area the reader is standing in", () => {
        expect(activeHrefs(links("/music"))).toStrictEqual(["/music"]);
    });

    it("keeps the parent area lit from anywhere beneath it", () => {
        // A song page is still "in" Musik; equality alone would leave the header blank.
        expect(activeHrefs(links("/music/albums/album-1"))).toStrictEqual(["/music"]);
    });

    it("does not light an area up for a path that merely starts the same way", () => {
        // "/musicians" is not inside "/music" — the boundary slash is what says so.
        expect(activeHrefs(links("/musicians"))).toStrictEqual([]);
    });

    it("survives a query string, so sorting a table does not drop the highlight", () => {
        expect(activeHrefs(links("/music/songs?sort=duration&dir=desc&page=2"))).toStrictEqual(["/music"]);
    });

    it("lights exactly one area at a time", () => {
        expect(activeHrefs(links("/music/songs"))).toHaveLength(1);
    });
});
