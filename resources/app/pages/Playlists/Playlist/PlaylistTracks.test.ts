import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, routerCalls } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlaylistTracks, { type PlaylistTrackRow } from "./PlaylistTracks.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * SortableJS, stubbed to a no-op constructor. It binds document-level pointer listeners the
 * moment it is built and expects elements with real geometry, neither of which happy-dom has —
 * and the DRAG is Playwright's job anyway. What this leaves testable is the keyboard path and
 * the order the list renders, which is all the logic there is.
 */
vi.mock("sortablejs", () => ({ default: class { destroy() {} } }));

/*
 * A playlist's rows. Three things live here and nowhere else.
 *
 * WHAT THE PLAY BUTTON MEANS, which is the one a reader cannot undo by pressing something
 * else: it queues the WHOLE playlist and starts at the row that was pressed. The obvious
 * wrong implementation — queue this one track — looks identical on screen and silently throws
 * away the list you were looking at.
 *
 * THE REORDER's keyboard half, which is where its logic is: the order the list renders and
 * the request that persists it. Alt+↑/↓ and the drag go through the same `move()`, so proving
 * one proves both.
 *
 * And what PHP cannot see: seconds rendered as a clock, and a chip that vanishes rather than
 * printing an empty pill when the tags never carried it. The props themselves — ownership,
 * the reader's own order, the entry ids — are `assertInertia`'s job in
 * tests/Feature/Playlists/PlaylistPageTest.php, and which chip appears at which VIEWPORT is
 * CSS, so it belongs to Playwright (Vitest compiles no styles at all).
 */

/** One entry with sensible defaults; tests override only what they are about. */
const track = (overrides: Partial<PlaylistTrackRow> = {}): PlaylistTrackRow => ({
    entryId: "entry-1",
    id: "track-1",
    name: "Airbag",
    artist: "Radiohead",
    album: "OK Computer",
    year: 1997,
    path: "Radiohead/OK Computer/01 Airbag.mp3",
    duration: 284,
    coverUrl: null,
    href: "/music/songs/track-1",
    streamUrl: "/music/songs/track-1/stream",
    ...overrides
});

/** Mount the list over a set of entries. */
const list = (tracks: PlaylistTrackRow[]) =>
    mountApp(PlaylistTracks, { props: { playlistId: "playlist-1", tracks } });

/** The fact chips, in DOM order. */
const facts = (wrapper: ReturnType<typeof list>): string[] =>
    wrapper.findAll(".playlist-tracks__fact").map(node => node.text());

/** The rows' titles, in DOM order. */
const titles = (wrapper: ReturnType<typeof list>): string[] =>
    wrapper.findAll(".playlist-tracks__name").map(node => node.text());

/** Press Alt+Arrow on the nth row's grip. */
const move = async (wrapper: ReturnType<typeof list>, index: number, key: string): Promise<void> => {
    wrapper
        .findAll("button.playlist-tracks__handle")
    [index].element.dispatchEvent(new KeyboardEvent("keydown", { key, altKey: true, bubbles: true }));
    await nextTick();
};

/** Three entries, in a known order. */
const three = (): PlaylistTrackRow[] => [
    track({ entryId: "e1", id: "a", name: "Airbag" }),
    track({ entryId: "e2", id: "b", name: "Bones" }),
    track({ entryId: "e3", id: "c", name: "Creep" })
];

describe("PlaylistTracks", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
    });

    describe("the play button", () => {
        it("queues the WHOLE playlist, not just the row that was pressed", () => {
            // The point of the button. Queueing one track would look identical on screen and
            // throw away the running order the reader is looking at.
            const { tracks: queued } = usePlayerQueue();

            list(three()).findAll(".playlist-tracks__play")[1].trigger("click");

            expect(queued.value.map(entry => entry.id)).toStrictEqual(["a", "b", "c"]);
        });

        it("starts at the row that was pressed, not at the top", () => {
            const { current } = usePlayerQueue();

            list(three()).findAll(".playlist-tracks__play")[2].trigger("click");

            expect(current.value?.name).toBe("Creep");
        });

        it("REPLACES whatever was queued, rather than appending to it", () => {
            const { tracks: queued, enqueue } = usePlayerQueue();
            enqueue(track({ id: "already-there", entryId: "e0" }));

            list(three()).findAll(".playlist-tracks__play")[0].trigger("click");

            expect(queued.value.map(entry => entry.id)).toStrictEqual(["a", "b", "c"]);
        });

        it("queues the order ON SCREEN, so a drag that is still in flight is honoured", async () => {
            // `entries`, not the prop: the reorder is optimistic, so for the length of a round
            // trip the two disagree — and the reader means the list they can see.
            const { tracks: queued } = usePlayerQueue();
            const wrapper = list(three());

            await move(wrapper, 0, "ArrowDown");
            wrapper.findAll(".playlist-tracks__play")[0].trigger("click");

            expect(queued.value.map(entry => entry.id)).toStrictEqual(["b", "a", "c"]);
        });

        it("is the row's only action, and says what it does", () => {
            // The enqueue button it replaced acted on the single track, which per-row is what
            // the hero's menu already does for the whole playlist. Both labels say "from here",
            // because a bare play triangle on a row reads as "play this one song".
            const wrapper = list([track({ name: "Airbag" })]);
            const play = wrapper.find(".playlist-tracks__play");

            expect(wrapper.findAll(".playlist-tracks__play")).toHaveLength(1);
            expect(play.attributes("aria-label")).toContain("Airbag");
            expect(play.text()).toBe("");
        });
    });

    describe("reordering", () => {
        it("moves a row down with Alt+ArrowDown", async () => {
            const wrapper = list(three());

            await move(wrapper, 0, "ArrowDown");

            expect(titles(wrapper)).toStrictEqual(["Bones", "Airbag", "Creep"]);
        });

        it("moves a row up with Alt+ArrowUp", async () => {
            const wrapper = list(three());

            await move(wrapper, 2, "ArrowUp");

            expect(titles(wrapper)).toStrictEqual(["Airbag", "Creep", "Bones"]);
        });

        it("persists to the playlist's own endpoint", async () => {
            const wrapper = list(three());

            await move(wrapper, 0, "ArrowDown");

            expect(routerCalls[routerCalls.length - 1]).toMatchObject({
                method: "put",
                url: "/playlists/playlist-1/tracks/order"
            });
        });

        it("does nothing at the ends of the list", async () => {
            // And leaves the keystroke alone rather than swallowing it, so a reader at the top
            // of the list can still use their arrow keys.
            const wrapper = list(three());

            await move(wrapper, 0, "ArrowUp");
            expect(titles(wrapper)).toStrictEqual(["Airbag", "Bones", "Creep"]);
            expect(routerCalls).toHaveLength(0);

            await move(wrapper, 2, "ArrowDown");
            expect(titles(wrapper)).toStrictEqual(["Airbag", "Bones", "Creep"]);
            expect(routerCalls).toHaveLength(0);
        });

        it("ignores an arrow without Alt, which is how a reader scrolls", async () => {
            const wrapper = list(three());

            wrapper
                .findAll("button.playlist-tracks__handle")[0]
                .element.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
            await nextTick();

            expect(titles(wrapper)).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });

        it("gives every row a grip, named for the track it moves", () => {
            // A real button rather than a decorative glyph, and NOT disabled: it is the tab stop
            // the keyboard alternative hangs off, and a disabled button leaves the tab order.
            const grip = list([track({ name: "Airbag" })]).find("button.playlist-tracks__handle");

            expect(grip.exists()).toBe(true);
            expect(grip.attributes("aria-label")).toContain("Airbag");
            expect(grip.attributes("aria-keyshortcuts")).toBe("Alt+ArrowUp Alt+ArrowDown");
        });
    });

    describe("sorting by path", () => {
        /*
         * The verb the hero's Sort button reaches for. It is here rather than on the page
         * because the list owns the order — and its value is that it happens in the CLICK: the
         * rows carry their own `path`, so there is nothing to wait for.
         */
        const paths = (): PlaylistTrackRow[] => [
            track({ entryId: "e1", name: "Zoo", path: "Zebra/album/01.mp3" }),
            track({ entryId: "e2", name: "Ant", path: "Ant/album/01.mp3" }),
            track({ entryId: "e3", name: "Mid", path: "Mango/album/01.mp3" })
        ];

        it("puts the rows in file order, immediately", async () => {
            const wrapper = list(paths());

            expect(wrapper.vm.sortByPath()).toBe(true);
            await nextTick();

            expect(titles(wrapper)).toStrictEqual(["Ant", "Mid", "Zoo"]);
        });

        it("sorts naturally, so track 2 precedes track 10", async () => {
            // `numeric: true`. Unpadded numbers are what a hand-made rip leaves behind, and a
            // plain string sort files 10 before 2.
            const wrapper = list([
                track({ entryId: "e1", name: "ten", path: "a/10.mp3" }),
                track({ entryId: "e2", name: "two", path: "a/2.mp3" })
            ]);

            wrapper.vm.sortByPath();
            await nextTick();

            expect(titles(wrapper)).toStrictEqual(["two", "ten"]);
        });

        it("persists the new order to the same endpoint a drag uses", () => {
            list(paths()).vm.sortByPath();

            expect(routerCalls[routerCalls.length - 1]).toMatchObject({
                method: "put",
                url: "/playlists/playlist-1/tracks/order"
            });
        });

        it("does nothing at all when the playlist is already in file order", () => {
            // Reported back so the page can say "already sorted" rather than claiming to have
            // done work — and no request, because there is nothing to record.
            const wrapper = list([
                track({ entryId: "e1", path: "a/1.mp3" }),
                track({ entryId: "e2", path: "b/1.mp3" })
            ]);

            expect(wrapper.vm.sortByPath()).toBe(false);
            expect(routerCalls).toHaveLength(0);
        });
    });

    describe("a row's facts", () => {
        it("formats the raw duration as a clock rather than seconds", () => {
            const shown = facts(list([track({ duration: 284 })]));

            expect(shown).toContain("4:44");
            expect(shown.join(" ")).not.toContain("284");
        });

        it("shows the artist, the album, the runtime and the year", () => {
            expect(facts(list([track({ artist: "Portishead", album: "Dummy", year: 1994 })]))).toStrictEqual([
                "Portishead",
                "Dummy",
                "4:44",
                "1994"
            ]);
        });

        it("drops a fact the tags never carried rather than printing an empty chip", () => {
            // A loose file crediting nobody, filed under no album, off an untagged rip.
            expect(facts(list([track({ artist: null, album: null, year: null })]))).toStrictEqual(["4:44"]);
        });

        it("drops the clock for a file with no duration rather than claiming 0:00", () => {
            expect(facts(list([track({ duration: null })]))).toStrictEqual([
                "Radiohead",
                "OK Computer",
                "1997"
            ]);
        });

        it("names each chip with an icon, so a value reads without a label", () => {
            // Grip and artwork lead the row, the four chips follow, the play button ends it.
            expect(iconNames(list([track()]))).toStrictEqual([
                "drag",
                "music",
                "artist",
                "album",
                "duration",
                "calendar",
                "play"
            ]);
        });
    });

    describe("the row as a whole", () => {
        it("shows the track's title", () => {
            expect(list([track({ name: "Let Down" })]).find(".playlist-tracks__name").text()).toBe("Let Down");
        });

        it("makes the title a link to the song, since the row itself cannot be one", () => {
            // The row holds a grip and a button, and an <a> may not contain interactive content
            // — so unlike a DataTable row or a Discography tile, the title is the only thing
            // here that navigates. If it stops being a link there is no way off this page.
            const title = list([track({ href: "/music/songs/track-9" })]).find(".playlist-tracks__name");

            expect(title.element.tagName).toBe("A");
            expect(title.attributes("href")).toBe("/music/songs/track-9");
        });

        it("keeps both controls OUT of that link", () => {
            // Nesting a button in an anchor renders perfectly and misbehaves: the press would
            // follow the link as well as run the handler, and assistive tech announces one
            // control where there are two.
            const wrapper = list([track()]);

            expect(wrapper.find("a.playlist-tracks__name button").exists()).toBe(false);
            expect(wrapper.findAll(".playlist-tracks__item button")).toHaveLength(2);
        });

        it("carries the track's artwork", () => {
            const art = list([track({ coverUrl: "/music/songs/track-1/cover" })]).find(".playlist-tracks__art img");

            expect(art.attributes("src")).toBe("/music/songs/track-1/cover");
            // Decorative: the title is the next thing in the row, so naming the picture too
            // would have a screen reader read every row twice.
            expect(art.attributes("alt")).toBe("");
        });
    });

    describe("the list as a whole", () => {
        it("renders one row per entry, in the order given", () => {
            expect(titles(list(three()))).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });

        it("renders the same track twice when the playlist holds it twice", () => {
            // Keyed on the ENTRY id, not the track's: a playlist may legitimately repeat one,
            // and two rows sharing a Vue key would make Sortable and Vue disagree about which
            // one moved.
            const wrapper = list([
                track({ entryId: "e1", id: "same-track" }),
                track({ entryId: "e2", id: "same-track" })
            ]);

            expect(wrapper.findAll(".playlist-tracks__item")).toHaveLength(2);
        });

        it("is a real list, so assistive tech announces how many entries there are", () => {
            const wrapper = list([track()]);

            expect(wrapper.find("ul").exists()).toBe(true);
            expect(wrapper.find("ul").attributes("aria-label")).toBe(translate("playlists.detail.label"));
        });

        it("says so when the playlist is empty, and offers no controls", () => {
            const wrapper = list([]);

            expect(wrapper.find("ul").exists()).toBe(false);
            expect(wrapper.text()).toBe(translate("playlists.detail.empty"));
        });
    });
});
