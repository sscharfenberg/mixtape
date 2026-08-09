import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { resetInertia } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlaylistTracks, { type PlaylistTrackRow } from "./PlaylistTracks.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * A playlist's rows, and above all WHAT THE TWO BUTTONS DO — which is the pair a reader
 * cannot undo by pressing the other one: play REPLACES the queue, enqueue APPENDS to it.
 * Getting them the wrong way round would look identical on screen and would quietly throw
 * away whatever somebody had lined up.
 *
 * The other half is what PHP cannot see: seconds rendered as a clock against the viewer's
 * locale, and a fact that vanishes rather than printing an empty chip when the tags never
 * carried it. The props themselves — ownership, the reader's own order, the entry ids — are
 * `assertInertia`'s job in tests/Feature/Playlists/PlaylistPageTest.php.
 */

/** One entry with sensible defaults; tests override only what they are about. */
const track = (overrides: Partial<PlaylistTrackRow> = {}): PlaylistTrackRow => ({
    entryId: "entry-1",
    id: "track-1",
    name: "Airbag",
    artist: "Radiohead",
    album: "OK Computer",
    year: 1997,
    duration: 284,
    coverUrl: null,
    href: "/music/songs/track-1",
    streamUrl: "/music/songs/track-1/stream",
    ...overrides
});

/** Mount the list over a set of entries. */
const list = (tracks: PlaylistTrackRow[]) => mountApp(PlaylistTracks, { props: { tracks } });

/** The fact chips of the first row, in DOM order. */
const facts = (wrapper: ReturnType<typeof list>): string[] =>
    wrapper.findAll(".playlist-tracks__fact").map(node => node.text());

/** A row's two controls: play first, then enqueue. */
const controls = (wrapper: ReturnType<typeof list>) => wrapper.findAll(".playlist-tracks__control");

/** Drain the toast singleton, which outlives a test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    while (activeToasts.value.length > 0) activeToasts.value.forEach(toast => removeToast(toast.id));
};

describe("PlaylistTracks", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
        drainToasts();
    });

    describe("the two verbs", () => {
        it("REPLACES the queue when a row is played", () => {
            const { tracks: queued, enqueue } = usePlayerQueue();
            enqueue(track({ id: "already-there", entryId: "e0" }));

            controls(list([track({ id: "the-one" })]))[0].trigger("click");

            expect(queued.value.map(entry => entry.id)).toStrictEqual(["the-one"]);
        });

        it("loads the played row, so the player bar has something to show", () => {
            const { current } = usePlayerQueue();

            controls(list([track({ name: "Karma Police" })]))[0].trigger("click");

            expect(current.value?.name).toBe("Karma Police");
        });

        it("APPENDS when a row is enqueued, leaving what is already queued alone", () => {
            const { tracks: queued, enqueue } = usePlayerQueue();
            enqueue(track({ id: "already-there", entryId: "e0" }));

            controls(list([track({ id: "the-new-one" })]))[1].trigger("click");

            expect(queued.value.map(entry => entry.id)).toStrictEqual(["already-there", "the-new-one"]);
        });

        it("says so when a row is enqueued, since nothing else on screen moves", async () => {
            const { activeToasts } = useToast();

            await controls(list([track()]))[1].trigger("click");

            expect(activeToasts.value).toHaveLength(1);
            expect(activeToasts.value[0].type).toBe("success");
        });

        it("acts on the row that was pressed, not the first one", () => {
            const { current } = usePlayerQueue();
            const wrapper = list([
                track({ entryId: "e1", id: "first", name: "Airbag" }),
                track({ entryId: "e2", id: "second", name: "Exit Music" })
            ]);

            // Four controls: play/enqueue, play/enqueue. The second row's play is index 2.
            controls(wrapper)[2].trigger("click");

            expect(current.value?.id).toBe("second");
        });

        it("names the track in each button, since neither carries a word of its own", () => {
            const [play, enqueue] = controls(list([track({ name: "Airbag" })]));

            expect(play.attributes("aria-label")).toContain("Airbag");
            expect(enqueue.attributes("aria-label")).toContain("Airbag");
            expect(play.text()).toBe("");
            expect(enqueue.text()).toBe("");
        });

        it("uses the play glyph for play and the playlist glyph for enqueue", () => {
            expect(iconNames(list([track()]))).toStrictEqual(["play", "playlist"]);
        });
    });

    describe("a row's facts", () => {
        it("formats the raw duration as a clock rather than seconds", () => {
            const shown = facts(list([track({ duration: 284 })]));

            expect(shown).toContain("4:44");
            expect(shown.join(" ")).not.toContain("284");
        });

        it("shows the artist, the album and the year", () => {
            expect(facts(list([track({ artist: "Portishead", album: "Dummy", year: 1994 })]))).toStrictEqual([
                "Portishead",
                "Dummy",
                "1994",
                "4:44"
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

        it("shows the track's title", () => {
            expect(list([track({ name: "Let Down" })]).find(".playlist-tracks__name").text()).toBe("Let Down");
        });

        it("makes the title a link to the song, since the row itself cannot be one", () => {
            // The row holds two buttons, and an <a> may not contain interactive content — so
            // unlike a DataTable row or a Discography tile, the title is the only thing here
            // that navigates. If it stops being a link there is no way off this page at all.
            const title = list([track({ href: "/music/songs/track-9" })]).find(".playlist-tracks__name");

            expect(title.element.tagName).toBe("A");
            expect(title.attributes("href")).toBe("/music/songs/track-9");
        });

        it("keeps the two controls OUT of that link", () => {
            // Nesting a button in an anchor renders perfectly and misbehaves: the press would
            // follow the link as well as run the handler, and assistive tech announces one
            // control where there are two.
            const wrapper = list([track()]);

            expect(wrapper.find("a.playlist-tracks__name button").exists()).toBe(false);
            expect(controls(wrapper)).toHaveLength(2);
        });
    });

    describe("the list as a whole", () => {
        it("renders one row per entry, in the order given", () => {
            const wrapper = list([
                track({ entryId: "e1", name: "Airbag" }),
                track({ entryId: "e2", name: "Paranoid Android" })
            ]);

            expect(wrapper.findAll(".playlist-tracks__name").map(node => node.text())).toStrictEqual([
                "Airbag",
                "Paranoid Android"
            ]);
        });

        it("renders the same track twice when the playlist holds it twice", () => {
            // Keyed on the ENTRY id, not the track's: a playlist may legitimately repeat one.
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
