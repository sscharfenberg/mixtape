import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import SubjectActions from "./SubjectActions.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The two verbs a Music hero carries, standing in its ActionPanel. They replaced
 * SubjectMenu's popover on 2026-08-11.
 *
 * WHAT IS THIS COMPONENT'S OWN, and so what is tested here: that the two verbs are the RIGHT
 * WAY ROUND. "Play" replaces the queue and "enqueue" appends — swapped, both buttons would
 * still look right and one of them would quietly throw away somebody's queue. Everything else
 * about them lives in `useSubjectTracks` (the round trip, the empty-subject warning, the
 * once-per-page fetch), which SubjectMenu.test.ts drives through the other consumer.
 *
 * There is nothing here about WHICH subject this is: the component takes no props any more,
 * because the labels name the verb rather than the thing. Which page offers a share button
 * beside it is the pages' decision now — asserted in GenrePage.test.ts (it has none) and in
 * `tests/e2e/app/share.spec.ts`.
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

/** Mount the pair, with the queue payload already on the page or not. */
const actions = (queueTracks?: unknown) => {
    setPage({
        props: { auth: { user: null }, csrfToken: "token", ...(queueTracks === undefined ? {} : { queueTracks }) }
    });

    return mountApp(SubjectActions);
};

/** Drain the toast singleton, which outlives a test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    while (activeToasts.value.length > 0) activeToasts.value.forEach(toast => removeToast(toast.id));
};

describe("SubjectActions", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
        drainToasts();
    });

    it("offers exactly two verbs, labelled by what they do", () => {
        // German is the default locale. Short labels on purpose (the owner's call): a button
        // in the hero of the thing itself does not need to name the thing, and two long
        // labels wrapped the row at hero width.
        const wrapper = actions();

        expect(wrapper.findAll("button")).toHaveLength(2);
        expect(wrapper.find(".subject-actions__play").text()).toBe("Abspielen");
        expect(wrapper.find(".subject-actions__enqueue").text()).toBe("Warteschlange");
    });

    it("REPLACES the queue on play, and loads the first track", async () => {
        const queue = usePlayerQueue();
        queue.enqueue([{ ...TRACKS[0], id: "old", name: "Something else" }]);

        const wrapper = actions(TRACKS);
        await wrapper.find(".subject-actions__play").trigger("click");
        await nextTick();

        expect(queue.tracks.value.map(track => track.id)).toStrictEqual(["aaaa", "bbbb"]);
        expect(queue.current.value?.id).toBe("aaaa");
    });

    it("APPENDS on enqueue, leaving what is already queued alone", async () => {
        const queue = usePlayerQueue();
        queue.enqueue([{ ...TRACKS[0], id: "old", name: "Something else" }]);

        const wrapper = actions(TRACKS);
        await wrapper.find(".subject-actions__enqueue").trigger("click");
        await nextTick();

        expect(queue.tracks.value.map(track => track.id)).toStrictEqual(["old", "aaaa", "bbbb"]);
        // …and it did not steal the player: the track that was loaded stays loaded.
        expect(queue.current.value?.id).toBe("old");
    });

    it("locks BOTH verbs while the tracks are being fetched", async () => {
        // Nothing on the page yet, so the first press asks for the optional prop. A second
        // press landing before it returns would queue the subject twice.
        const wrapper = actions();

        await wrapper.find(".subject-actions__play").trigger("click");
        await nextTick();

        expect(routerCalls[0].options).toMatchObject({ only: ["queueTracks"] });
        expect(wrapper.find(".subject-actions__play").attributes("disabled")).toBe("");
        expect(wrapper.find(".subject-actions__enqueue").attributes("disabled")).toBe("");
    });
});
