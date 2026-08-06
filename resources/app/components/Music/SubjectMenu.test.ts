import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import SubjectMenu from "./SubjectMenu.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * What the two verbs DO, and the difference between them — which is the whole point of the
 * component: "play" replaces the queue, "enqueue" appends to it. Getting those the wrong way
 * round would still look right in the menu and would quietly throw away somebody's queue.
 *
 * The FETCH is only asserted as far as "it asked for the right prop and locked the menu while
 * it waited". Whether a partial reload really returns the subject's tracks is Inertia's and the
 * controller's business, covered in `tests/Feature/Music/SubjectQueueTest.php` (the props) and
 * in Playwright (the round trip) — the test mock here records calls rather than answering them,
 * so anything more would be asserting the mock.
 */

/** Two tracks in the shape the server sends them. */
const TRACKS = [
    {
        id: "aaaa",
        name: "Airbag",
        artist: "Radiohead",
        album: "OK Computer",
        coverUrl: null,
        duration: 284,
        href: "/music/songs/aaaa",
        streamUrl: "/music/songs/aaaa/stream"
    },
    {
        id: "bbbb",
        name: "Let Down",
        artist: "Radiohead",
        album: "OK Computer",
        coverUrl: null,
        duration: 299,
        href: "/music/songs/bbbb",
        streamUrl: "/music/songs/bbbb/stream"
    }
];

/** Mount the menu for a subject, with the payload already on the page or not. */
const menu = (subject: "artist" | "album" | "genre" | "song" = "artist", queueTracks?: unknown) => {
    setPage({ props: { auth: { user: null }, ...(queueTracks === undefined ? {} : { queueTracks }) } });

    return mountApp(SubjectMenu, { props: { subject } });
};

/** The menu's two items, in order: play, then enqueue. */
const items = (wrapper: ReturnType<typeof mountApp>) => wrapper.findAll(".popover-list-item");

/** Drain the toast singleton, which outlives a test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    while (activeToasts.value.length > 0) activeToasts.value.forEach(toast => removeToast(toast.id));
};

describe("SubjectMenu", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
        drainToasts();
    });

    it("offers exactly two verbs", () => {
        expect(items(menu())).toHaveLength(2);
    });

    it("names the subject in the play item, so the two verbs cannot be confused", () => {
        // German is the default locale, and each page passes its own noun.
        expect(items(menu("artist"))[0].text()).toContain("Künstler");
        expect(items(menu("album"))[0].text()).toContain("Album");
        expect(items(menu("genre"))[0].text()).toContain("Genre");
        expect(items(menu("song"))[0].text()).toContain("Titel");
    });

    it("REPLACES the queue on play, and loads the first track", async () => {
        const queue = usePlayerQueue();
        queue.enqueue([{ ...TRACKS[0], id: "old", name: "Something else" }]);

        const wrapper = menu("artist", TRACKS);
        await items(wrapper)[0].trigger("click");
        await nextTick();

        expect(queue.tracks.value.map(track => track.id)).toStrictEqual(["aaaa", "bbbb"]);
        expect(queue.current.value?.id).toBe("aaaa");
    });

    it("APPENDS on enqueue, leaving what is already queued alone", async () => {
        const queue = usePlayerQueue();
        queue.enqueue([{ ...TRACKS[0], id: "old", name: "Something else" }]);

        const wrapper = menu("artist", TRACKS);
        await items(wrapper)[1].trigger("click");
        await nextTick();

        expect(queue.tracks.value.map(track => track.id)).toStrictEqual(["old", "aaaa", "bbbb"]);
        // …and it did not steal the player: the track that was loaded stays loaded.
        expect(queue.current.value?.id).toBe("old");
    });

    it("says how many tracks it queued", async () => {
        const wrapper = menu("artist", TRACKS);
        await items(wrapper)[1].trigger("click");
        await nextTick();

        const { activeToasts } = useToast();
        expect(activeToasts.value).toHaveLength(1);
        expect(activeToasts.value[0].message).toContain("2");
    });

    it("asks the server for the tracks by name, and locks the menu until they land", async () => {
        // Nothing on the page yet: the payload is an optional prop, so it has to be requested.
        const wrapper = menu("artist");

        await items(wrapper)[0].trigger("click");
        await nextTick();

        expect(routerCalls).toHaveLength(1);
        expect(routerCalls[0].method).toBe("reload");
        expect(routerCalls[0].options).toMatchObject({ only: ["queueTracks"] });

        // Both verbs are disabled while it is in flight — a second press would queue the
        // subject twice.
        expect(items(wrapper).map(item => item.attributes("disabled"))).toStrictEqual(["", ""]);
    });

    it("does not ask twice once the payload is on the page", async () => {
        // The prop survives for the life of the page, so play-then-enqueue is one round trip.
        const wrapper = menu("artist", TRACKS);

        await items(wrapper)[0].trigger("click");
        await items(wrapper)[1].trigger("click");
        await nextTick();

        expect(routerCalls).toHaveLength(0);
    });

    it("warns instead of emptying the queue when there is nothing playable", async () => {
        // A subject whose tracks are all audiobook chapters, say. `playNow([])` would clear
        // the queue and leave the reader with nothing — worse than doing nothing.
        const queue = usePlayerQueue();
        queue.enqueue([{ ...TRACKS[0], id: "old" }]);

        const wrapper = menu("artist", []);
        await items(wrapper)[0].trigger("click");
        await nextTick();

        expect(queue.tracks.value.map(track => track.id)).toStrictEqual(["old"]);
        expect(useToast().activeToasts.value[0].type).toBe("warning");
    });
});
