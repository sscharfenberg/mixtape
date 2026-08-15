import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { useSubjectTracks } from "Composables/useSubjectTracks";
import { resetToastsForTests } from "Composables/useToast";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The fetch behind a hero's "play" and "queue" buttons.
 *
 * WHAT IS WORTH TESTING HERE IS THE CONCURRENCY, not the verbs themselves — those are thin
 * wrappers over `usePlayerQueue`, which has its own spec. Two presses in the same tick each
 * started their own `router.reload`, and INERTIA CANCELS THE FIRST: a cancelled visit never
 * fires `onSuccess`, so that promise settled through nothing and the verb waiting on it did
 * nothing at all, with no error anywhere to say so.
 *
 * `busy` did not prevent it. It disables the controls, but it is a flag rather than a lock and
 * it is set AFTER the decision to fetch has been made, so two calls in one tick both get past
 * it. Reaching that needs a keyboard repeat or two controls on one page — the audiobook page
 * has several.
 *
 * Driven through the real verbs rather than the private `loadTracks`, because "both verbs
 * acted" is the property that broke; a test of the fetch alone would pass on a queue that only
 * one of them ever reached.
 */

/** A track as the server sends it onto the queue. */
const track = (id: string) => ({
    id,
    name: `Track ${id}`,
    artist: "Radiohead",
    album: "The Bends",
    coverUrl: null,
    duration: 120,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** How many partial reloads the router was asked for. */
const reloads = () => routerCalls.filter(call => call.method === "reload").length;

/** The options object the pending reload was given, so a test can land its payload. */
const pendingReload = () =>
    routerCalls.find(call => call.method === "reload")!.options as {
        onSuccess: () => void;
        onFinish: () => void;
    };

/**
 * Land `tracks` on the in-flight reload, the way a successful partial visit would.
 */
const landPayload = (tracks: ReturnType<typeof track>[]): void => {
    const options = pendingReload();
    setPage({ props: { queueTracks: tracks } });
    options.onSuccess();
    options.onFinish();
};

/**
 * The composable, called from inside a real component.
 *
 * Mounted rather than invoked directly because it opens with `useI18n()`, which needs an active
 * i18n instance and therefore a component setup.
 */
const subjectTracks = (): ReturnType<typeof useSubjectTracks> => {
    let api: ReturnType<typeof useSubjectTracks> | undefined;

    mountApp(
        defineComponent({
            setup() {
                api = useSubjectTracks();

                return () => null;
            }
        })
    );

    return api!;
};

describe("useSubjectTracks", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetToastsForTests();
        setPage({ props: {} });
    });

    it("sends ONE request for two presses in the same tick, and both verbs act on it", async () => {
        const { playSubject, enqueueSubject } = subjectTracks();

        // Play then queue, with no await between them — one hero, two buttons.
        const played = playSubject();
        const queued = enqueueSubject();

        expect(reloads()).toBe(1);

        landPayload([track("a"), track("b")]);
        await Promise.all([played, queued]);

        // FOUR: `playNow` replaced the queue with two, `enqueue` appended the same two. Before
        // the shared promise, one of the verbs was waiting on a cancelled visit and silently
        // never ran, leaving two.
        expect(usePlayerQueue().tracks.value).toHaveLength(4);
    });

    it("starts a fresh request once the first has settled", async () => {
        const { playSubject } = subjectTracks();

        const first = playSubject();
        landPayload([track("a")]);
        await first;

        // A later press must not be handed the settled promise from a page the reader has since
        // navigated past, so the memo is cleared however the first one ended. The prop is
        // cleared EXPLICITLY: `setPage` merges rather than replaces, so `{ props: {} }` would
        // leave the payload from the reload above in place and short-circuit before the memo.
        setPage({ props: { queueTracks: undefined } });
        void playSubject();

        expect(reloads()).toBe(2);
    });

    it("asks for nothing when the page already carries the tracks", async () => {
        // The common case on a detail page: the prop rode down with the page, so pressing play
        // must not cost a round trip at all.
        setPage({ props: { queueTracks: [track("a")] } });

        await subjectTracks().playSubject();

        expect(reloads()).toBe(0);
        expect(usePlayerQueue().tracks.value).toHaveLength(1);
    });
});
