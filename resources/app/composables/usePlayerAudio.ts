/******************************************************************************
 * usePlayerAudio
 * The playing half of the player: one HTMLAudioElement, the state the transport UI
 * draws from, and the rules for moving through the queue as tracks finish.
 *
 * Module-level state, the same no-Pinia singleton pattern as usePlayerQueue — and
 * for a stronger reason here. There must be exactly ONE element making sound; a
 * second instance would play over the first. The element itself is rendered by
 * PlayerBar (which is mounted once, in FullLayout, so it survives Inertia page
 * swaps) and handed here through `attach()`. In the DOM rather than a bare
 * `new Audio()` because a real element is what iOS treats as a first-class media
 * element, and because it is then inspectable from a browser test.
 *
 * A NATIVE <audio>, not vidstack — re-evaluated and decided in docs/player.md. Every
 * feature the player needs is HTMLAudioElement plus the Media Session API, and the
 * skin is the half we would have had to fight.
 *
 * THE THREE THINGS THAT ARE EASY TO GET WRONG, all of them here:
 *
 * 1. **Auto-advance rides the `ended` event, never a timer.** In a backgrounded tab
 *    `setInterval` is throttled to roughly once a minute and `requestAnimationFrame`
 *    stops dead, so a timer-driven queue would stall the moment you switched tabs —
 *    which is precisely when a listener least wants it to. Media events keep firing.
 *    For the same reason the progress readout is always taken FROM the element's
 *    `currentTime` rather than counted up locally: a hidden tab throttles the
 *    `timeupdate` rate, and a counter would drift where a reading cannot.
 *
 * 2. **Duration comes from the queue track, not the element.** A VBR MP3 with no
 *    Xing/Info header reports `Infinity` until the whole file has been downloaded.
 *    getID3 already measured every file during the library scan and `QueueTrack.duration`
 *    carries that, so the total is right from the first frame. The element is only
 *    the fallback, for a row whose file carried no duration at all.
 *
 * 3. **`isPlaying` is INTENT, corrected by reality.** Deriving it from `element.paused`
 *    looks obvious and breaks the track boundary: browsers fire `pause` immediately
 *    before `ended`, so a state-derived flag reads "paused" exactly when the `ended`
 *    handler needs to know the listener still wants music, and playback stops after
 *    one track. So `play()`/`pause()` set the intent, the `pause` EVENT only clears it
 *    when the element did not merely reach the end, and a `play()` the browser refuses
 *    (autoplay policy) puts it back. That also keeps the glyph from flickering while a
 *    seek re-buffers.
 *
 * Playback never starts on its own: nothing here calls `play()` except a user gesture
 * or an auto-advance that follows one, so hydrating a stored queue at page load leaves
 * a paused player rather than a browser blocking it (or, worse, not blocking it).
 *
 * TWO THINGS DELIBERATELY LIVE ELSEWHERE, because they shared nothing with the above:
 *
 *   - **The output level** — `Composables/usePlayerVolume`. It touches two element
 *     properties and one storage key and never sees the intent flag, the queue pointer
 *     or the teardown list. This module still owns the element and hands it over via
 *     `bindVolumeElement()`, so there is exactly one owner.
 *   - **The playback speed** — `Composables/usePlayerSpeed`. Same shape as the level, and
 *     split for the same reason. It also owns the SKIM (a held Space), so the setting and
 *     the temporary doubling on top of it stay two numbers rather than one that a hold
 *     would quietly persist.
 *   - **Talking to the OS** — `Utils/mediaSession`. Stateless output: lock screen,
 *     notification shade, media keys. It was interleaved with element wiring inside
 *     `attach()`, which read as one procedure and was two.
 *
 * What is left is deliberately NOT split further. `attach`/`detach`, the intent flag,
 * the readings, `load` and the queue watcher share one teardown list and one
 * invariant, and all three traps above live exactly at those seams — spreading them
 * across files would move the bug surface somewhere nobody looks.
 *****************************************************************************/
import type { ComputedRef, Ref } from "vue";
import { computed, ref, watch } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { applyPlaybackRate, bindSpeedElement } from "Composables/usePlayerSpeed";
import { bindVolumeElement } from "Composables/usePlayerVolume";
import * as session from "Utils/mediaSession";
import { announcePlaybackFailure, forgetPlaybackFailure } from "Utils/playbackError";

/** One contiguous stretch of audio the browser already holds, in seconds. */
export type BufferedRange = {
    /** Where the stretch starts, in seconds from the beginning of the track. */
    start: number;
    /** Where it ends, in seconds. */
    end: number;
};

/** Return type of {@link usePlayerAudio}. */
export type UsePlayerAudioReturn = {
    /** Whether the listener wants music right now — see point 3 of the module note. */
    isPlaying: Ref<boolean>;
    /** The play cursor in seconds, read from the element. */
    currentTime: Ref<number>;
    /** The loaded track's playing time in seconds, or 0 while nothing is loaded. */
    duration: ComputedRef<number>;
    /** What the browser has already downloaded — what the timeline draws as buffered. */
    buffered: Ref<BufferedRange[]>;
    /** Take ownership of the element PlayerBar rendered. */
    attach: (element: HTMLAudioElement) => void;
    /** Give it up again on unmount, taking every listener with it. */
    detach: () => void;
    /** Start (or resume) playback. Must be reached from a user gesture the first time. */
    play: () => void;
    /** Pause, leaving the cursor where it is. */
    pause: () => void;
    /** Pause if playing, play if not — what the transport's big button does. */
    toggle: () => void;
    /** Move the cursor, clamped into the track. */
    seek: (seconds: number) => void;
};


// Module-level state — one element, one set of readings, however many consumers.
const isPlaying = ref<boolean>(false);
const currentTime = ref<number>(0);
const buffered = ref<BufferedRange[]>([]);
/**
 * The element's own idea of the duration, kept only as a fallback.
 *
 * `0` rather than `NaN` when unknown, because it is divided by: a bare
 * `currentTime / NaN` silently makes every width in the timeline `NaN%`.
 */
const elementDuration = ref<number>(0);

/** The element under this module's control, or null before PlayerBar has mounted. */
let element: HTMLAudioElement | null = null;

/** Teardown for everything `attach()` wired up, so `detach()` needs no list of its own. */
let teardown: Array<() => void> = [];

/** The queue this player walks. Read at module scope: it is a singleton too. */
const queue = usePlayerQueue();

/** The loaded track's playing time — the queue's figure, the element's only as a fallback. */
const duration = computed<number>(() => queue.current.value?.duration ?? elementDuration.value);

/**
 * Snapshot the element's `buffered` TimeRanges as a plain array.
 *
 * Copied rather than held onto because `TimeRanges` is a live view — keeping a
 * reference would hand the template an object whose contents change without any
 * reactivity to notice. Usually one range; a seek past the buffer starts a second.
 */
function readBuffered(source: HTMLAudioElement): BufferedRange[] {
    const ranges: BufferedRange[] = [];

    for (let index = 0; index < source.buffered.length; index += 1) {
        ranges.push({ start: source.buffered.start(index), end: source.buffered.end(index) });
    }

    return ranges;
}

/**
 * Publish the cursor to the OS.
 *
 * A local wrapper because the module below is deliberately stateless: it takes the three
 * numbers rather than reading them, so the player is the only thing that knows the total
 * comes from the QUEUE (getID3's measurement) while the position comes from the ELEMENT.
 * That mismatch is exactly what makes `setPositionState` throw, and it is guarded there.
 */
function publishPositionState(): void {
    session.publishPositionState(duration.value, currentTime.value, element?.playbackRate ?? 1);
}

/** Mirror the play INTENT — not `element.paused` — so the lock screen matches the bar. */
function publishPlaybackState(): void {
    session.publishPlaybackState(isPlaying.value);
}

/**
 * Ask the element to play, and believe the answer.
 *
 * `play()` returns a promise that REJECTS when the browser refuses — no user gesture
 * yet, or the element has no usable source. Without this the intent flag would sit at
 * "playing" over a silent element and the button would offer to pause nothing.
 */
function requestPlayback(): void {
    if (!element) return;

    isPlaying.value = true;
    publishPlaybackState();

    void element.play().catch(() => {
        isPlaying.value = false;
        publishPlaybackState();

        /*
         * THE ELEMENT IS CONSULTED, NOT THE REJECTION, because the two reasons a play()
         * is refused deserve opposite treatment. The autoplay policy is the usual one and
         * nothing is broken — the next press works, and a toast about it would be noise.
         * A source the element has given up on is the other, and only that one leaves a
         * MediaError behind. Announcing it here is what answers a press on a dead track;
         * the first failure is reported by the `error` listener, and the reporter drops
         * whichever of the two arrives second.
         */
        if (element?.error) announcePlaybackFailure(element.error, queue.current.value);
    });
}

/**
 * Point the element at a track from the beginning.
 *
 * The readings are reset with it, and both matter: a stale `currentTime` would leave
 * the timeline showing the previous track's position for the fraction of a second
 * before the first `timeupdate` (visible as a jump backwards at every track
 * boundary), and stale buffered ranges would credit the new track with the old one's
 * downloaded bytes.
 *
 * ALREADY-LOADED BYTES ARE KEPT. Re-assigning `src` — even the identical URL — runs
 * the media load algorithm again and re-downloads the file, so the two cases that
 * reach here with the same URL are rewound instead: repeat-one, and the same song
 * sitting twice in the queue (which is a normal thing to do). Nothing is refetched
 * and the buffer indicator does not lose what it had.
 */
function load(track: QueueTrack, autoplay: boolean): void {
    if (!element) return;

    currentTime.value = 0;

    if (element.getAttribute("src") === track.streamUrl) {
        element.currentTime = 0;
    } else {
        buffered.value = [];
        elementDuration.value = 0;
        element.src = track.streamUrl;

        /*
         * A new source resets `playbackRate` to 1 in some engines and keeps it in others,
         * so it is re-asserted rather than assumed either way. It matters for exactly one
         * case, and that case is reachable by accident: a hold-to-skim that runs past the
         * end of a track. Whichever engine you are on, the next song then starts at the
         * speed the key is still asking for.
         */
        applyPlaybackRate();
    }

    session.publishMetadata(track);

    if (autoplay) requestPlayback();
}

/**
 * Silence the element and let go of the file — the queue was emptied.
 *
 * `removeAttribute` plus `load()` rather than `src = ""`: an empty src resolves
 * against the document and sets the browser downloading the PAGE as audio, which
 * fails noisily in the console. Removing the attribute and reloading is the
 * documented way to tell a media element it has no source, and it also aborts an
 * in-flight download instead of leaving it running for music nobody can hear.
 */
function stopAndUnload(audio: HTMLAudioElement): void {
    audio.pause();
    audio.removeAttribute("src");
    audio.load();
    isPlaying.value = false;
    currentTime.value = 0;
    buffered.value = [];
    elementDuration.value = 0;
    session.publishMetadata(null);
    publishPlaybackState();
}

/**
 * A track finished: move the queue on, or stop at the end of it.
 *
 * The wrap-around for repeat lives in the queue's `next()`, so the only thing this
 * has to know is what a `true` means. The index check is the one case a watcher
 * cannot see: on a one-track queue with repeat on, `next()` reports success without
 * the pointer moving — nothing changed for `watch(current)` to fire on — yet the
 * track is meant to play again, so it is restarted from here.
 */
function handleEnded(): void {
    const before = queue.currentIndex.value;

    if (!queue.next()) {
        isPlaying.value = false;
        publishPlaybackState();

        return;
    }

    if (queue.currentIndex.value === before && queue.current.value) load(queue.current.value, true);
}

/**
 * Read / write the one audio element.
 *
 * Returns the module-level refs themselves, so the transport UI and anything else
 * that asks are looking at the same playback, with no props in between.
 */
export function usePlayerAudio(): UsePlayerAudioReturn {
    /** Start or resume playback — the only path that may be reached from a gesture. */
    function play(): void {
        /*
         * The listener has asked again, so a failure they were already told about may be
         * told again. The element keeps ONE MediaError for as long as a dead source is
         * loaded, so without this the second press would be met with silence — which is
         * the failure mode the toast exists to remove.
         */
        forgetPlaybackFailure();

        if (!element) {
            /*
             * THERE IS NOTHING TO PLAY ON YET, and dropping the request here is what made
             * "play this artist" fill the queue and then sit paused (reported 2026-08-06).
             * The bar renders the element and only exists while the queue has a track, so a
             * press that FILLS an empty queue reaches this function a tick before the element
             * it needs: `playNow()` is synchronous, mounting is not.
             *
             * So the intent is kept rather than discarded — `isPlaying` is intent, never a
             * reading of the element (see the module note) — and `attach()` honours it the
             * moment the element arrives.
             */
            isPlaying.value = true;

            return;
        }

        // The queue can be loaded before the element ever had a source (a hydrated
        // queue, or the very first press after an enqueue).
        if (!element.getAttribute("src") && queue.current.value) {
            load(queue.current.value, true);

            return;
        }

        requestPlayback();
    }

    /** Pause, leaving the cursor where it is so the same press resumes. */
    function pause(): void {
        isPlaying.value = false;
        publishPlaybackState();
        element?.pause();
    }

    /** What the transport's big button does. */
    function toggle(): void {
        if (isPlaying.value) {
            pause();

            return;
        }
        play();
    }

    /**
     * Move the cursor.
     *
     * `currentTime` is written locally as well as onto the element because the
     * element answers with `seeking` and only later `timeupdate`: without this the
     * timeline would snap back to where the drag started for a frame or two, which
     * reads as a failed seek. Clamped because a click at the very end of the track
     * rounds past the duration, and assigning that throws in some engines.
     */
    function seek(seconds: number): void {
        if (!element) return;

        const target = Math.min(Math.max(seconds, 0), duration.value || 0);
        currentTime.value = target;
        element.currentTime = target;
        publishPositionState();
    }


    /**
     * Take ownership of PlayerBar's element: wire every listener, and load whatever
     * the queue already has without starting it.
     *
     * Idempotent by way of `detach()` — a remount (or a test mounting the bar twice)
     * must not leave two sets of listeners on one element, which would advance the
     * queue by two at every track boundary.
     */
    function attach(audio: HTMLAudioElement): void {
        /*
         * READ BEFORE THE DETACH BELOW, which clears it. `play()` keeps the intent when it is
         * pressed before an element exists (see its note), and `detach()` — reasonably — resets
         * every reading including that flag, so asking for it after the reset would always find
         * false and the queued press would be lost a second time.
         */
        const requested = isPlaying.value;

        detach();
        element = audio;

        // The level lives in its own singleton; this module owns the element, so it hands
        // it over rather than letting a second module claim it.
        bindVolumeElement(audio);

        // The speed lives in its own singleton for the same reason the level does; this
        // module owns the element, so it hands it over rather than letting a second module
        // claim it. Binding also re-asserts the stored speed onto a fresh element, which
        // starts at 1 whatever the listener last chose.
        bindSpeedElement(audio);

        /** Bind a media event and register its removal, so `detach()` needs no list of its own. */
        const on = <K extends keyof HTMLMediaElementEventMap>(
            event: K,
            handler: (payload: HTMLMediaElementEventMap[K]) => void
        ): void => {
            audio.addEventListener(event, handler);
            teardown.push(() => audio.removeEventListener(event, handler));
        };

        // Always a reading, never a count — see point 1 of the module note.
        on("timeupdate", () => {
            currentTime.value = audio.currentTime;
            buffered.value = readBuffered(audio);
            publishPositionState();
        });

        // `progress` is the download's own event, so the buffer indicator keeps
        // filling while the player is paused — which is exactly when a listener is
        // waiting to see whether it is safe to scrub ahead.
        on("progress", () => {
            buffered.value = readBuffered(audio);
        });

        on("durationchange", () => {
            elementDuration.value = Number.isFinite(audio.duration) ? audio.duration : 0;
        });

        // The seek itself, so the cursor lands as soon as the element agrees rather
        // than at the next timeupdate.
        on("seeked", () => {
            currentTime.value = audio.currentTime;
            buffered.value = readBuffered(audio);
        });

        on("ended", handleEnded);

        on("play", () => {
            isPlaying.value = true;
            publishPlaybackState();
        });

        // `ended` is why this is conditional: browsers fire `pause` immediately before
        // it, and clearing the intent there would stop the queue after one track (point
        // 3 of the module note). Anything else — the OS pause button, headphones
        // unplugged — is a real pause and must show as one.
        on("pause", () => {
            if (audio.ended) return;
            isPlaying.value = false;
            publishPlaybackState();
        });

        // A track whose file went missing between library scans, or a dropped
        // connection. Stopping is the honest response: the glyph goes back to play
        // rather than sitting on pause over silence. Silence is ALSO what a paused
        // player looks like, though, so since 2026-08-07 the listener is told which
        // track failed and why — Utils/playbackError owns the message and the
        // say-it-once rule. The queue deliberately does not skip on: a bad file is
        // worth noticing, not stepping over.
        on("error", () => {
            isPlaying.value = false;
            publishPlaybackState();
            announcePlaybackFailure(audio.error ?? null, queue.current.value);
        });

        /*
         * Re-read the cursor when the tab comes back.
         *
         * `timeupdate` is throttled while the tab is hidden, so the last reading can be
         * up to a minute stale — the audio kept playing, the number did not keep up. The
         * bar would show the old position until the next event. Nothing here assumes the
         * clock ran; it simply asks the element where it actually is.
         */
        const resync = (): void => {
            if (document.visibilityState !== "visible" || !element) return;
            currentTime.value = element.currentTime;
            buffered.value = readBuffered(element);
        };
        document.addEventListener("visibilitychange", resync);
        teardown.push(() => document.removeEventListener("visibilitychange", resync));

        /*
         * OS transport controls — lock screen, notification shade, media keys, a car head
         * unit. These are the handlers that make the queue work when the page is not on
         * screen at all, which is the whole point of wiring Media Session.
         *
         * Its teardown joins this element's list, so `detach()` still undoes everything in
         * one place — the guarantee that a remount cannot leave two sets of handlers
         * advancing the queue twice.
         */
        teardown.push(
            ...session.bindActionHandlers({
                play,
                pause,
                previous: () => queue.previous(),
                next: () => queue.next(),
                seekBy: seconds => seek(currentTime.value + seconds),
                seekTo: seek
            })
        );

        /*
         * Follow the queue's pointer.
         *
         * Wired here rather than at module scope so it lives and dies with the element,
         * and so a remount cannot leave two watchers loading the same track twice.
         *
         * It watches the TRACK, not the index, and that distinction is load-bearing:
         * removing a queue entry above the one playing shifts the index without changing
         * what is loaded, and an index watcher would restart the song mid-listen.
         * Autoplay follows the INTENT flag, which is what carries playback across a
         * track boundary and what keeps a hydrated queue silent at page load.
         */
        teardown.push(
            watch(
                () => queue.current.value,
                track => {
                    if (!element) return;

                    if (!track) {
                        stopAndUnload(element);

                        return;
                    }

                    load(track, isPlaying.value);
                }
            )
        );

        /*
         * A queue restored from storage, or one built before the bar mounted. Loaded so the
         * timeline can draw its total and the first press starts instantly.
         *
         * WHETHER IT ALSO PLAYS IS THE INTENT, not a constant — and `false` used to be
         * hardcoded here, which is the other half of the bug `play()` documents above. Page
         * load leaves the intent false, so a hydrated queue stays silent (a page load is not a
         * user gesture and a browser would refuse anyway). A press that filled an empty queue
         * leaves it TRUE, and this is the first moment there is an element to honour it on.
         */
        if (queue.current.value) load(queue.current.value, requested);
        publishPlaybackState();
    }

    /**
     * Drop every listener and forget the element.
     *
     * The volume module is told too, so it is not left holding a node that has left the
     * document — but the LEVEL itself survives, because unmounting the bar when the queue
     * empties must not forget a preference.
     */
    function detach(): void {
        for (const undo of teardown) undo();
        teardown = [];
        element = null;
        bindVolumeElement(null);
        // The next element starts with no history, so a failure announced on the last one
        // must not silence the same failure on it.
        forgetPlaybackFailure();
        isPlaying.value = false;
        currentTime.value = 0;
        buffered.value = [];
        elementDuration.value = 0;
    }

    return { isPlaying, currentTime, duration, buffered, attach, detach, play, pause, toggle, seek };
}

/**
 * Reset the singleton — tests only.
 *
 * The module-level element and readings outlive a test, exactly as the queue's do, so
 * a spec that presses play leaks into the next one. Exported rather than worked around
 * with module mocking, the same way the app's other singletons are drained (see
 * docs/testing.md → module singletons).
 */
export function resetPlayerAudioForTests(): void {
    for (const undo of teardown) undo();
    teardown = [];
    element = null;
    forgetPlaybackFailure();
    isPlaying.value = false;
    currentTime.value = 0;
    buffered.value = [];
    elementDuration.value = 0;

    // NOT the output level: that is usePlayerVolume's singleton now, and a spec that
    // needs it drained calls `resetPlayerVolumeForTests()` itself. Resetting it from here
    // would quietly re-couple the two modules the moment someone added a field.
}
