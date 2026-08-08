import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { PlaylistEntry } from "Types/playlists";
import PlaylistsPage from "./PlaylistsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * PlaylistsController's own feature test pins the props — who is listed, in what order,
 * with which numbers, and which of them are null. So this stays on what PHP cannot see.
 *
 * THE STRUCTURE ITSELF is asserted here, which is unusual for this suite and deliberate:
 * the row's menu trigger must be a SIBLING of its <a>, never a descendant, because an <a>
 * may not contain interactive content — nest it and the click both runs the button and
 * follows the link, in every browser, silently. Nothing else in the stack can catch that,
 * and it is exactly the kind of thing a later styling pass moves by accident.
 *
 * The rest is formatting and absences. `createdAt` / `updatedAt` arrive as ISO-8601 and
 * are rendered in the VIEWER's locale and timezone, which the server can neither decide
 * nor check; raw seconds become a human playtime. And three tiles have to DISAPPEAR
 * rather than print a placeholder — no description, an empty playlist's zero playtime, and
 * a playlist nothing has happened to since it was made.
 */

/** One playlist, defaulted around a filled-in, already-edited row. */
const playlist = (overrides: Partial<PlaylistEntry> = {}): PlaylistEntry => ({
    id: "playlist-1",
    name: "Sunday morning",
    description: "Quiet things.",
    tracks: 12,
    duration: 4335,
    createdAt: "2024-03-09T18:04:00Z",
    updatedAt: "2024-04-01T09:30:00Z",
    ...overrides
});

/** Mount the listing over a set of playlists. */
const page = (playlists: PlaylistEntry[] = [playlist()], locale: "de" | "en" = "de") =>
    mountApp(PlaylistsPage, { props: { playlists }, locale });

describe("PlaylistsPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("heads the page with the playlists label", () => {
        expect(page().text()).toContain(translate("header.siteMenu.playlists"));
    });

    it("publishes its breadcrumb trail", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.playlists", icon: "playlist" }
        ]);
    });

    it("offers the create form, on an empty page as much as a full one", () => {
        for (const playlists of [[], [playlist()]]) {
            const link = page(playlists).find('a[href="/playlists/create"]');

            expect(link.exists()).toBe(true);
            expect(link.text()).toContain(translate("playlists.createLink"));
        }
    });

    it("puts one row in the list per playlist", () => {
        const wrapper = page([playlist({ id: "a" }), playlist({ id: "b", name: "Nachtfahrt" })]);

        expect(wrapper.findAll("ul.playlist__list > li.playlist")).toHaveLength(2);
    });

    it("names each playlist inside its own link", () => {
        const row = page([playlist({ name: "Nachtfahrt" })]).find("li.playlist");
        const link = row.find("a.playlist__link");

        expect(link.attributes("href")).toBe("https://www.google.com");
        expect(link.find("span.playlist__title").text()).toBe("Nachtfahrt");
    });

    it("keeps the menu trigger OUT of the row's link, which may not contain a button", () => {
        // The reason this is a test and not a comment: nesting it is invalid markup that
        // renders perfectly, and a click would then both open the menu and follow the link.
        const row = page().find("li.playlist");

        expect(row.find("a.playlist__link button").exists()).toBe(false);
        expect(row.find(".popover button").exists()).toBe(true);
    });

    it("gives every row's menu its own popover, so two rows cannot share one", () => {
        const wrapper = page([playlist({ id: "a" }), playlist({ id: "b" })]);
        const targets = wrapper.findAll(".popover button").map(button => button.attributes("popovertarget"));

        expect(targets).toStrictEqual(["playlist-menu-a", "playlist-menu-b"]);
        expect(new Set(targets).size).toBe(2);
    });

    it("offers the edit action in the menu", () => {
        expect(page().find(".popover-list-item").text()).toContain(translate("playlists.menu.edit"));
    });

    it("shows a playlist's description", () => {
        expect(page().find("span.playlist__description").text()).toBe("Quiet things.");
    });

    it("renders no description element at all when there is none", () => {
        // Not an empty span, and not a dash: the row simply has one less thing in it.
        expect(page([playlist({ description: null })]).find(".playlist__description").exists()).toBe(false);
    });

    it("counts the tracks, including the zero a brand-new playlist has", () => {
        const text = page([playlist({ tracks: 0, duration: null })]).text();

        expect(text).toContain(translate("playlists.facts.tracks"));
        expect(text).toContain("0");
    });

    it("reads the playtime as an amount of time rather than a clock", () => {
        // 4335s = 1h 12m 15s. A total is read as an amount, and the breakdown grows an
        // hours part on its own — which a playlist regularly needs.
        const text = page().text();

        expect(text).toContain(translate("playlists.facts.duration"));
        expect(text).toContain("1 Stunde, 12 Minuten, 15 Sekunden");
    });

    it("says nothing about playtime for an empty playlist", () => {
        // The server sends null there, and "0 Sekunden" beside a track count of 0 says
        // nothing twice.
        expect(page([playlist({ tracks: 0, duration: null })]).text()).not.toContain(
            translate("playlists.facts.duration")
        );
    });

    it("formats both dates in the viewer's locale", () => {
        expect(page([playlist()], "de").text()).toContain("09.03.2024");
        expect(page([playlist()], "en").text()).toContain("Mar 9, 2024");

        expect(page([playlist()], "de").text()).toContain("01.04.2024");
        expect(page([playlist()], "en").text()).toContain("Apr 1, 2024");
    });

    it("drops a date tile when the server sent no timestamp", () => {
        // Rather than rendering "Invalid Date", which is what a raw pass-through gives.
        const text = page([playlist({ createdAt: null })]).text();

        expect(text).not.toContain(translate("playlists.facts.createdAt"));
    });

    it("says nothing about a change for a playlist nothing has happened to", () => {
        // `updatedAt` is null until something moves it — the server answers that, because
        // "was this changed" is a fact about the data rather than about formatting.
        expect(page([playlist({ updatedAt: null })]).text()).not.toContain(
            translate("playlists.facts.updatedAt")
        );
    });

    it("explains itself when the account has no playlists", () => {
        const text = page([]).text();

        expect(text).toContain(translate("playlists.empty.headline"));
        expect(text).toContain(translate("playlists.empty.text"));
    });

    it("renders no list at all when there is nothing to list", () => {
        expect(page([]).find(".playlist__list").exists()).toBe(false);
    });

    it("shows no empty-state copy once a playlist exists", () => {
        expect(page().text()).not.toContain(translate("playlists.empty.headline"));
    });
});
