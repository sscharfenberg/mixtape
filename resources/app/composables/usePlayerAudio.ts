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
 * THREE THINGS DELIBERATELY LIVE ELSEWHERE, because they shared nothing with the above:
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
import { usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, ref, watch } from "vue";
import { bindAnalyserElement } from "Composables/useAudioAnalyser";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { bindPositionSource, notePlaybackProgress, takeRestoredPosition, usePlayerQueue } from "Composables/usePlayerQueue";
import { applyPlaybackRate, bindSpeedElement } from "Composables/usePlayerSpeed";
import { bindVolumeElement } from "Composables/usePlayerVolume";
import { bindSleepStop, consumeTrackEndStop, noteSleepProgress } from "Composables/useSleepTimer";
import * as session from "Utils/mediaSession";
import { announcePlaybackFailure, forgetPlaybackFailure } from "Utils/playbackError";
import { reportPlay } from "Utils/playBeacon";

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
    /**
     * Whether the queue ran to its end rather than being paused.
     *
     * Both leave the player stopped, so nothing about the ELEMENT can tell them apart — this is
     * set at the one moment the difference exists (a track ending with nothing to follow) and
     * cleared as soon as anything plays or a different track is loaded. The Now Playing page's
     * status badge is what reads it: "paused" is waiting for a press, "end of queue" has nothing
     * left to press.
     */
    queueFinished: Ref<boolean>;
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

/** See the return type: set only when the queue genuinely ran out, cleared by anything playing. */
const queueFinished = ref<boolean>(false);
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

/**
 * How much of a track must be left — and how far in the listener must be — for a stored
 * position to be worth restoring at all.
 *
 * The same number at both ends, and both ends matter. Under it, "resume" means starting a
 * song at 0:04, which is noise. Within it of the end, the resumed track finishes almost
 * immediately and the queue moves on, which reads as the app skipping a song. In between,
 * picking up where you were is what a listener actually asked for.
 */
const RESUME_GUARD_SECONDS = 30;

/**
 * How often, in seconds of PLAYBACK, the position is stored while a track runs — the
 * operator's setting (`mixtape.player.position_heartbeat`), shared through Inertia.
 *
 * Read per call rather than captured, since the props arrive with the page. Zero or absent
 * turns the heartbeat off and leaves the boundaries (pause, track change, tab hidden) to
 * carry the position on their own, which is what the config note describes.
 */
function heartbeatSeconds(): number {
    return usePage().props.player?.positionHeartbeat ?? 0;
}

/** Where the position stood at the last heartbeat, so the next one can measure against it. */
let lastHeartbeatAt = 0;

/** A stored position waiting for metadata, in seconds. Zero when there is nothing to restore. */
let pendingResume = 0;

/**
 * What counts as having listened to a track: HALF OF IT, or four minutes, whichever comes
 * first.
 *
 * Last.fm's rule, and it is the right shape for a collection of songs and the occasional
 * hour-long mix — half a track is a real listen, and the cap means a DJ set does not need
 * thirty minutes of anybody's life before it registers.
 *
 * The threshold is measured in HEARD SECONDS, never in cursor position, and that is the
 * decision worth defending: "has `currentTime` passed halfway" makes a drag of the timeline
 * into a play, so scrubbing an album would mark every track on it as listened. What is
 * accumulated below is the ground the cursor covered by PLAYING.
 */
const PLAY_FRACTION = 0.5;
const PLAY_CAP_SECONDS = 240;

/** Seconds of this track genuinely heard, seeks excluded. Reset by every `load()`. */
let heardSeconds = 0;

/** Where the cursor was at the last reading, so a `timeupdate` can be turned into a delta. */
let lastHeardAt = 0;

/** Whether this load has already been reported, so one listen is one row. */
let playReported = false;

/**
 * Count the ground covered since the last reading, and report a play once it is enough.
 *
 * ONLY POSITIVE DELTAS, and only between seeks: `seeked` resyncs the mark without crediting
 * anything, so dragging forward earns nothing and dragging backwards costs nothing. There is
 * deliberately no upper bound on a delta — a hidden tab throttles `timeupdate` to a trickle
 * while the audio plays on, and those seconds were heard as surely as any others.
 *
 * REPEAT IS NOT GUARDED. `load()` clears the flag, and repeat-one rewinds through it, so ten
 * loops are ten plays — which is what ten loops are. A track left on repeat overnight is a
 * question for the ranking query (distinct days played, say), not a reason to throw away
 * what actually happened.
 */
function countHeardTime(audio: HTMLAudioElement, track: QueueTrack | null): void {
    const delta = audio.currentTime - lastHeardAt;
    lastHeardAt = audio.currentTime;

    if (delta > 0) heardSeconds += delta;
    if (playReported || !track) return;

    const total = duration.value;

    // Nothing to measure against yet: a file whose duration neither the scan nor the
    // element has produced would otherwise cross a threshold of zero instantly.
    if (total <= 0) return;

    if (heardSeconds >= Math.min(total * PLAY_FRACTION, PLAY_CAP_SECONDS)) {
        playReported = true;
        reportPlay(track.id);
    }
}

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

    // A different track is loaded, so whatever the queue did before this is no longer what the
    // player is doing — stepping back from a finished queue must not still read as finished.
    queueFinished.value = false;
    currentTime.value = 0;
    // A new load is a new listen — including the rewind repeat-one comes back through, which
    // is what makes ten loops ten plays.
    heardSeconds = 0;
    lastHeardAt = 0;
    playReported = false;

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
    queueFinished.value = false;
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

    /*
     * THE SLEEP TIMER'S OTHER MODE, and it is asked here rather than anywhere else because
     * this is the one moment "the end of the chapter" exists. Consumed rather than read, so
     * the flag cannot stop the next track too.
     *
     * IT STILL ADVANCES THE POINTER — it simply does not play. Stopping ON the finished
     * chapter looks like the more literal reading of "stop at the end of this one" and is the
     * wrong behaviour twice over. The element is left in its `ended` state, and `play()` on an
     * ended element seeks back to the start, so the press the next morning would REPLAY the
     * chapter the listener had just finished. And nothing would record where they got to:
     * `queue.next()` is what commits the pointer, and the `pause` handler that would otherwise
     * store a position early-returns on `audio.ended`. Cueing the next chapter paused is what
     * a press at the boundary would have done, and it is what leaves the bookmark on the
     * chapter a reader wants to wake up to.
     *
     * The intent is cleared FIRST because the queue's watcher reads it: `load(track,
     * isPlaying.value)` on the next tick is what decides whether the cued chapter also starts.
     */
    if (consumeTrackEndStop()) {
        isPlaying.value = false;
        /*
         * `queueFinished` only where the book really ran out — the last chapter ending under a
         * timer is both a deliberate stop and an exhausted queue, and the Now Playing badge
         * should say so. Anywhere else it stays false: somebody asked to be left here, and
         * "end of queue" over a book with 600 chapters left would be a lie.
         */
        queueFinished.value = !queue.next();
        publishPlaybackState();

        return;
    }

    if (!queue.next()) {
        isPlaying.value = false;
        // THE QUEUE REALLY RAN OUT, which is the only place that can be known. A stopped player
        // looks identical whether the listener pressed pause or the last track finished with
        // nothing behind it, and those are entirely different things to say — so the difference is
        // recorded at the one moment it exists rather than guessed at later from `hasNext`, which
        // is false on the last track however it got there.
        queueFinished.value = true;
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
             * "play this artist" fill the queue and then sit paused.
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
     *
     * THE CEILING IS ONLY APPLIED WHEN THERE IS ONE. `duration` is the queue's figure with the
     * element's as fallback, so it is zero for a track whose payload carried no duration and
     * whose metadata has not arrived — and clamping to zero there turns every seek into a jump
     * to the start. That is silent: the caller asked for 0:42, the cursor sits at 0:00, and
     * nothing reports a failure. The audiobook resume is the caller that would meet it, since
     * it seeks the moment the chapter is loaded.
     */
    function seek(seconds: number): void {
        if (!element) return;

        const known = duration.value;
        const wanted = Math.max(seconds, 0);
        const target = known > 0 ? Math.min(wanted, known) : wanted;
        currentTime.value = target;
        element.currentTime = target;
        // Same reason as the resume: the mark moves with the cursor, whatever order the
        // element's own events arrive in.
        lastHeardAt = target;
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

        // The Now Playing visualiser's analyser, handed over the same way — and DELIBERATELY
        // inert until something asks for readings. Routing an element through an AudioContext
        // cannot be undone, so a listener who never opens that page is never routed at all
        // (useAudioAnalyser's banner has the argument, and the measurement behind it).
        bindAnalyserElement(audio);

        /*
         * The queue persists the play position but cannot read it — it lives on this
         * element, and that module is imported by this one rather than the other way round.
         * So it gets a getter, the same handshake the two modules above use in reverse.
         */
        bindPositionSource(() => audio.currentTime);

        /*
         * The sleep timer stops the music but does not own the element or the play intent,
         * so it is handed the one function that does both. Same handshake as the position
         * source above, and for the same reason — that module is imported by this one, so an
         * import back would be a cycle.
         */
        bindSleepStop(pause);

        lastHeartbeatAt = 0;
        // Taken here, before the load below, and taken ONCE: this is the track a page load
        // came back holding, and it is the only one a stored position can belong to.
        pendingResume = takeRestoredPosition();

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

            /*
             * THE POSITION HEARTBEAT, counted in PLAYED seconds rather than by a timer.
             * `timeupdate` keeps firing in a backgrounded tab where a `setInterval` is
             * throttled to once a minute — and a tab left playing in the background is
             * exactly the one whose position is worth keeping. It also means a paused
             * player writes nothing at all, with no flag to check.
             *
             * `Math.abs`, because a seek moves the cursor in either direction and dragging
             * backwards is as much a change as playing forwards.
             */
            const heartbeat = heartbeatSeconds();

            if (heartbeat > 0 && Math.abs(audio.currentTime - lastHeartbeatAt) >= heartbeat) {
                lastHeartbeatAt = audio.currentTime;
                notePlaybackProgress();
            }

            // The listen itself, measured on the same event and for the same reason it is
            // the right one: it keeps firing while the tab is hidden.
            countHeardTime(audio, queue.current.value);

            /*
             * The sleep timer's fade and its stop, driven from here rather than from a timer
             * of its own — for the third time on this event, and for the same reason both
             * times above give: a hidden tab throttles `setInterval` to roughly once a
             * minute while media events keep arriving. A phone with the screen off is the
             * case the whole feature exists for, and it is the case a timer would miss.
             * The timer keeps an interval too, for a PAUSED player, where this never fires.
             */
            noteSleepProgress();
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

        /*
         * WHERE A RESTORED POSITION IS APPLIED, and NOT because an early write would be lost.
         * That is worth stating plainly, because it is the obvious explanation and it is
         * wrong: a `currentTime` assigned while `readyState` is HAVE_NOTHING becomes the
         * element's DEFAULT PLAYBACK START POSITION and survives metadata loading. Measured in
         * the engine this app runs in — written at readyState 0, read back unchanged after
         * `loadedmetadata` — and pinned by a spec so the myth cannot come back.
         *
         * It is here for the GUARDS BELOW, which need a total to compare against. `duration`
         * prefers the queue's figure, but a track whose payload carried none has only the
         * element's, and that does not exist until metadata does. Applying the resume here
         * means one path that is correct for both, rather than one that works until somebody
         * queues a file the scanner could not measure.
         *
         * The value is consumed once (see the queue's `takeRestoredPosition`), so only the
         * track a page load came back holding is ever resumed — every later track starts
         * where it should, at zero.
         */
        on("loadedmetadata", () => {
            if (pendingResume <= 0) return;

            const seconds = pendingResume;
            pendingResume = 0;

            // The queue's figure first (getID3 measured it at scan time), the element's as
            // the fallback for a file that carried no duration at all.
            const total = duration.value || (Number.isFinite(audio.duration) ? audio.duration : 0);

            if (seconds < RESUME_GUARD_SECONDS) return;
            if (total > 0 && total - seconds < RESUME_GUARD_SECONDS) return;

            audio.currentTime = seconds;
            // Written locally too, for the reason `seek()` gives: the element answers with
            // `seeking` and only later `timeupdate`, and the bar would show 0:00 until then.
            currentTime.value = seconds;
            lastHeartbeatAt = seconds;
            // AND the listen mark, without waiting for `seeking` to be delivered: a restored
            // position is the one jump that is guaranteed to be large, and crediting it as
            // time heard is exactly the bug this seek caused.
            lastHeardAt = seconds;
            publishPositionState();
        });

        // The seek itself, so the cursor lands as soon as the element agrees rather
        // than at the next timeupdate.
        /*
         * A JUMP IS DISCOUNTED WHEN IT STARTS, not when it lands, and the difference is a real
         * bug: one track played to five minutes recording FOUR plays. `timeupdate` fires when
         * the position changes — including DURING a seek — and nothing promises it arrives after
         * `seeked`. So the reading that follows a restored position is a jump of everything heard
         * in the previous session (250 seconds in one delta) landing as time heard now, past the
         * threshold, on every page load of a track resumed past four minutes.
         *
         * Both events move the mark, because either can be the first to tell us.
         */
        const markSeek = (): void => {
            lastHeardAt = audio.currentTime;
        };

        on("seeking", markSeek);

        on("seeked", () => {
            currentTime.value = audio.currentTime;
            buffered.value = readBuffered(audio);
            markSeek();
        });

        on("ended", handleEnded);

        on("play", () => {
            isPlaying.value = true;
            // Anything playing means the queue is not finished, whatever it was a moment ago.
            queueFinished.value = false;
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
            // A deliberate stop, and the likeliest moment for the tab to be abandoned
            // afterwards — so the position is stored here rather than waiting for a
            // heartbeat that may never come.
            lastHeartbeatAt = audio.currentTime;
            notePlaybackProgress();
        });

        // A track whose file went missing between library scans, or a dropped
        // connection. Stopping is the honest response: the glyph goes back to play
        // rather than sitting on pause over silence. Silence is ALSO what a paused
        // player looks like, though, so the listener is told which
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
         * A queue restored from storage, or one built before the bar mounted. Pointed at the
         * track so the element is cued and a press has somewhere to start — but it FETCHES
         * NOTHING, because the bar carries `preload="none"` (which that attribute's comment
         * explains: "metadata" cost five requests and megabytes on every reload of a long
         * track, for a duration the queue already knew).
         *
         * WHETHER IT ALSO PLAYS IS THE INTENT, not a constant. Hardcoding `false` here is the
         * other half of the bug `play()` documents above. Page
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
     * EVERY MODULE HOLDING THE ELEMENT IS TOLD, not just the ones this function grew up with:
     * volume, speed, the analyser and the queue's position source each keep their own
     * reference, and one left un-nulled is a module pointing at a node that has left the
     * document until something happens to bind the next one. The volume LEVEL and the speed
     * both survive as values, because unmounting the bar when the queue empties must not
     * forget a preference — it is the node that is dropped, not the setting.
     */
    function detach(): void {
        for (const undo of teardown) undo();
        teardown = [];
        element = null;
        bindVolumeElement(null);
        bindSpeedElement(null);
        bindAnalyserElement(null);
        // The queue must not keep a closure over an element that has left the document.
        bindPositionSource(null);
        // Which also CANCELS a running sleep timer, deliberately: the bar carrying its mark
        // has gone, so a timer left counting would be state nothing on screen can show.
        bindSleepStop(null);
        pendingResume = 0;
        lastHeartbeatAt = 0;
        heardSeconds = 0;
        lastHeardAt = 0;
        playReported = false;
        // The next element starts with no history, so a failure announced on the last one
        // must not silence the same failure on it.
        forgetPlaybackFailure();
        isPlaying.value = false;
        currentTime.value = 0;
        buffered.value = [];
        elementDuration.value = 0;
    }

    return { isPlaying, queueFinished, currentTime, duration, buffered, attach, detach, play, pause, toggle, seek };
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
    bindPositionSource(null);
    // The stop closure would otherwise point at the previous spec's `pause`. Cancelling the
    // timer with it is a side effect rather than the goal, and a welcome one here — a spec
    // that armed a timer must not leave an interval ticking into the next file.
    bindSleepStop(null);
    pendingResume = 0;
    lastHeartbeatAt = 0;
    heardSeconds = 0;
    lastHeardAt = 0;
    playReported = false;
    forgetPlaybackFailure();
    isPlaying.value = false;
    queueFinished.value = false;
    currentTime.value = 0;
    buffered.value = [];
    elementDuration.value = 0;

    // NOT the output level: that is usePlayerVolume's singleton now, and a spec that
    // needs it drained calls `resetPlayerVolumeForTests()` itself. Resetting it from here
    // would quietly re-couple the two modules the moment someone added a field.
}
