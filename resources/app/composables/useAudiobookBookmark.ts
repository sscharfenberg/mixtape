import { router } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { onScopeDispose, ref, watch } from "vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";

/** Where a reader left off in one book, as AudiobookController sends it. */
export type AudiobookBookmark = {
    /** The chapter they were on. */
    trackId: string;
    /** How far into THAT CHAPTER, in milliseconds. */
    positionMs: number;
};

/** What {@link useAudiobookBookmark} hands the book page. */
export type UseAudiobookBookmarkReturn = {
    /** The bookmark as it stands, updated as the reader listens. Null for an unstarted book. */
    bookmark: Ref<AudiobookBookmark | null>;
    /** Play the book, resuming at the bookmark when there is one. */
    resume: () => Promise<void>;
    /** Play the book from its first chapter, ignoring any bookmark. */
    restart: () => Promise<void>;
};

/**
 * Per-book resume for the audiobook page — the feature the whole area exists for: put a
 * 673-chapter book down for a fortnight and come back to chapter 279 rather than to page one.
 *
 * IT IS NOT `player_states`, and the distinction is the point. That row is the LIVE player,
 * one per user, holding whatever is playing now; this is a row per (reader, BOOK), so three
 * books can be in flight and an evening spent on one costs you nothing in the others.
 *
 * THE WRITE RIDES THE PLAYER'S OWN HEARTBEAT rather than a timer of its own. `usePlayerAudio`
 * already updates `currentTime` off the audio element's `timeupdate`, which keeps counting in
 * a backgrounded tab where a `setInterval` would be throttled to once a minute — which is
 * exactly the tab somebody left a book playing in. Writes are spaced by
 * `mixtape.player.position_heartbeat` seconds of PLAYBACK, so a paused player writes nothing.
 *
 * IT ONLY EVER WRITES THIS BOOK'S OWN CHAPTERS. The queue is shared with music (the owner's
 * call), so what is loaded may be a song, or a chapter of a different book, and neither has
 * any business moving this page's bookmark.
 *
 * @param audiobookId the book this page is about
 * @param initial     the bookmark the server sent with the page, or null
 * @param chapterIds  this book's chapter ids, so a foreign track can be recognised
 * @param playFrom    starts the book at one chapter — the page's `playSubjectFrom`
 */
export const useAudiobookBookmark = (
    audiobookId: string,
    initial: AudiobookBookmark | null,
    chapterIds: () => string[],
    playFrom: (trackId: string) => Promise<boolean>
): UseAudiobookBookmarkReturn => {
    const { current } = usePlayerQueue();
    const { currentTime, seek } = usePlayerAudio();

    const bookmark = ref<AudiobookBookmark | null>(initial);

    /** Seconds of playback at the last write, so the heartbeat can be measured against it. */
    let writtenAt = 0;

    /** How often to store the position, in seconds of playback. Mirrors the queue's own step. */
    const HEARTBEAT_SECONDS = 30;

    /**
     * Store where the reader has got to.
     *
     * `preserveState` and `preserveScroll` because this is a background write on a page the
     * reader is looking at: without them a heartbeat would re-key the page component mid-play
     * — the same class of bug the prefetch rule exists for (CLAUDE.md → prefetch). `only: []`
     * asks the server for no props back at all; the response is a 204 anyway.
     */
    const store = (trackId: string, positionMs: number): void => {
        bookmark.value = { trackId, positionMs };

        router.put(
            `/audiobooks/${audiobookId}/bookmark`,
            { trackId, positionMs },
            { preserveState: true, preserveScroll: true, only: [] }
        );
    };

    /**
     * Follow playback, writing on the heartbeat.
     *
     * The guard order matters: a track that is not one of this book's chapters must not reset
     * the timer either, or switching to a song and back would write a position measured from
     * the song.
     */
    watch(currentTime, seconds => {
        const track = current.value;
        if (track === null || !chapterIds().includes(track.id)) return;

        // A new chapter is worth storing immediately — that is the fact a reader most wants
        // remembered, and waiting 30 seconds to record it loses it to a closed tab.
        if (bookmark.value?.trackId !== track.id) {
            writtenAt = seconds;
            store(track.id, Math.round(seconds * 1000));

            return;
        }

        if (Math.abs(seconds - writtenAt) < HEARTBEAT_SECONDS) return;

        writtenAt = seconds;
        store(track.id, Math.round(seconds * 1000));
    });

    /**
     * Play the book, picking up where the reader left off.
     *
     * The seek happens AFTER the chapter is loaded and only if it really is the bookmarked
     * one: `playFrom` resolves false when the payload and the page have gone out of step, and
     * seeking a track that never started would move whatever was playing before.
     */
    const resume = async (): Promise<void> => {
        const mark = bookmark.value;

        if (mark === null) {
            await playFrom(chapterIds()[0] ?? "");

            return;
        }

        const started = await playFrom(mark.trackId);
        if (started && mark.positionMs > 0) seek(mark.positionMs / 1000);
    };

    /** Play from the first chapter, leaving the bookmark alone until playback moves it. */
    const restart = async (): Promise<void> => {
        await playFrom(chapterIds()[0] ?? "");
    };

    // Nothing to tear down but the watcher, which the scope owns; this is here so a future
    // pending write has somewhere to be cancelled.
    onScopeDispose(() => {
        writtenAt = 0;
    });

    return { bookmark, resume, restart };
};
