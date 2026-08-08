import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { getLayoutProps, resetInertia, routerCalls } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { PlaylistEntry } from "Types/playlists";
import PlaylistsPage from "./PlaylistsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * SortableJS, stubbed to a no-op constructor. It binds document-level pointer listeners the
 * moment it is built and expects elements with real geometry, neither of which happy-dom has —
 * and the DRAG is Playwright's job anyway. What this leaves testable is the keyboard path and
 * the order the page renders, which is all the logic there is.
 */
vi.mock("sortablejs", () => ({ default: class { destroy() {} } }));

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

    it("keeps both controls OUT of the row's link, which may not contain a button", () => {
        // The reason this is a test and not a comment: nesting either is invalid markup that
        // renders perfectly, and a click would then both press the control and follow the
        // link. The whole entry being clickable is what makes it tempting to nest them.
        const row = page().find("li.playlist");

        expect(row.find("a.playlist__link button").exists()).toBe(false);
        expect(row.find(".playlist__handle").exists()).toBe(true);
        expect(row.find(".playlist__menu button").exists()).toBe(true);
    });

    it("gives every row a reorder grip, named for the playlist it moves", () => {
        // A real button rather than a decorative glyph, and NOT disabled: reordering is not
        // built yet, and a disabled control would leave the tab order and stop being
        // announced — so the affordance would vanish for the readers most likely to need it.
        const grip = page([playlist({ name: "Nachtfahrt" })]).find("button.playlist__handle");

        expect(grip.attributes("type")).toBe("button");
        expect(grip.attributes("disabled")).toBeUndefined();
        expect(grip.attributes("aria-label")).toContain("Nachtfahrt");
    });

    it("publishes each row's position, which is what staggers the ring animations", () => {
        // The delay is `--playlist-index` * a negative step, so a column of entries drifts
        // instead of pulsing in unison. Lose the index and every ring locks to the same
        // angle — a change nothing else in the stack would notice.
        const rows = page([playlist({ id: "a" }), playlist({ id: "b" }), playlist({ id: "c" })]).findAll(
            "li.playlist"
        );

        expect(rows.map(row => row.attributes("style"))).toStrictEqual([
            "--playlist-index: 0;",
            "--playlist-index: 1;",
            "--playlist-index: 2;"
        ]);
    });

    it("gives every row's menu its own popover, so two rows cannot share one", () => {
        const wrapper = page([playlist({ id: "a" }), playlist({ id: "b" })]);
        const targets = wrapper.findAll(".popover button").map(button => button.attributes("popovertarget"));

        expect(targets).toStrictEqual(["playlist-menu-a", "playlist-menu-b"]);
        expect(new Set(targets).size).toBe(2);
    });

    it("points each row's menu at that playlist's own metadata form", () => {
        // The href carries the row's id, so a menu that lost it would open the wrong
        // playlist's form — or, with a stale id, someone else's 404.
        const item = page([playlist({ id: "abc-123" })]).find("a.popover-list-item");

        expect(item.attributes("href")).toBe("/playlists/abc-123/edit");
        expect(item.text()).toContain(translate("playlists.menu.editMetadata"));
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

    describe("reordering", () => {
        /*
         * The KEYBOARD half, which is where the logic is. A drag is a stream of pointer events
         * over elements with real geometry, so it belongs to Playwright — SortableJS is mocked
         * out of these tests entirely (see the mock at the top of the file), and a "drag" here
         * would only assert the mock's arithmetic. Alt+↑/↓ goes through the same `move()`, so
         * what is proven here is proven for both: the order the page renders, and the request
         * that persists it.
         */

        /** Three entries, in a known order. */
        const three = () => [
            playlist({ id: "a", name: "Ambient" }),
            playlist({ id: "b", name: "Metal" }),
            playlist({ id: "c", name: "Zydeco" })
        ];

        /** The titles as rendered, in DOM order. */
        const titles = (wrapper: ReturnType<typeof page>) =>
            wrapper.findAll(".playlist__title").map(node => node.text());

        /** Press Alt+Arrow on the nth entry's grip. */
        const move = async (wrapper: ReturnType<typeof page>, index: number, key: string) => {
            wrapper
                .findAll("button.playlist__handle")[index]
                .element.dispatchEvent(new KeyboardEvent("keydown", { key, altKey: true, bubbles: true }));
            await nextTick();
        };

        it("moves an entry down with Alt+ArrowDown", async () => {
            const wrapper = page(three());

            await move(wrapper, 0, "ArrowDown");

            expect(titles(wrapper)).toStrictEqual(["Metal", "Ambient", "Zydeco"]);
        });

        it("moves an entry up with Alt+ArrowUp", async () => {
            const wrapper = page(three());

            await move(wrapper, 2, "ArrowUp");

            expect(titles(wrapper)).toStrictEqual(["Ambient", "Zydeco", "Metal"]);
        });

        it("persists the whole new order, not just the entry that moved", async () => {
            // The server renumbers from what it is sent, so a partial list would leave the rest
            // interleaved — ReorderPlaylistsRequest refuses one outright.
            const wrapper = page(three());

            await move(wrapper, 0, "ArrowDown");

            expect(routerCalls[routerCalls.length - 1]).toMatchObject({
                method: "put",
                url: "/playlists/order"
            });
        });

        it("does nothing at the ends of the listing", async () => {
            // And leaves the keystroke alone rather than swallowing it, so a reader at the top
            // of the list can still use their arrow keys.
            const wrapper = page(three());

            await move(wrapper, 0, "ArrowUp");
            expect(titles(wrapper)).toStrictEqual(["Ambient", "Metal", "Zydeco"]);
            expect(routerCalls).toHaveLength(0);

            await move(wrapper, 2, "ArrowDown");
            expect(titles(wrapper)).toStrictEqual(["Ambient", "Metal", "Zydeco"]);
            expect(routerCalls).toHaveLength(0);
        });

        it("ignores an arrow without Alt, which is how a reader scrolls", async () => {
            const wrapper = page(three());

            wrapper
                .findAll("button.playlist__handle")[0]
                .element.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
            await nextTick();

            expect(titles(wrapper)).toStrictEqual(["Ambient", "Metal", "Zydeco"]);
        });
    });
});
