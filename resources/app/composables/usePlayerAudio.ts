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
 *****************************************************************************/
import type { ComputedRef, Ref } from "vue";
import { computed, ref, watch } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";

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
    /** Output level, 0–1. `1` is the browser's own default: unity gain, no attenuation. */
    volume: Ref<number>;
    /** Whether output is muted, which is separate from a level of zero. */
    isMuted: Ref<boolean>;
    /** Nothing audible either way — what the control draws its mute glyph from. */
    isSilent: ComputedRef<boolean>;
    /** Set the level, clamped to 0–1; anything above zero also lifts a mute. */
    setVolume: (value: number) => void;
    /** Mute, or come back to the level muting interrupted. */
    toggleMute: () => void;
};

/**
 * Where the level is remembered.
 *
 * NOT user-scoped, unlike the queue's payload. How loud this machine's speakers want
 * to be is a fact about the machine and whoever is sitting at it, so two people
 * sharing a browser inheriting one level is right where inheriting one queue is not.
 */
const VOLUME_STORAGE_KEY = "mixtape.volume.v1";

/**
 * The level a fresh browser gives an <audio>: unity gain, nothing attenuated.
 *
 * Kept as the default deliberately, and it is why this app sounds louder than
 * YouTube for the same song. YouTube normalises loudness — it measures each upload
 * and turns DOWN anything mastered hotter than roughly -14 LUFS, which most modern
 * music is by a wide margin. Playing a file untouched is therefore not "too loud",
 * it is unattenuated, and the platform people compare it to is the one being quiet.
 * Lowering this number would only mean everything starts quiet and gets turned up;
 * the real fix is per-track normalisation (ReplayGain tags, which getID3 can already
 * read during the library scan) applied as a gain per track. Not built.
 */
const FULL_VOLUME = 1;

// Module-level state — one element, one set of readings, however many consumers.
const isPlaying = ref<boolean>(false);
const currentTime = ref<number>(0);
const buffered = ref<BufferedRange[]>([]);
const volume = ref<number>(FULL_VOLUME);
const isMuted = ref<boolean>(false);

/**
 * The level to return to when a mute is lifted.
 *
 * Needed because the two ways of silencing the player have to be undoable in the same
 * gesture: muting at 60% must come back to 60%, and un-muting after the slider was
 * dragged to zero has to arrive at something audible or the button does nothing
 * visible and reads as broken.
 */
let levelBeforeMute = FULL_VOLUME;

/** Whether the stored level has been read yet — once per page, on the first attach. */
let volumeHydrated = false;

/** Nothing audible: muted, or turned all the way down. One glyph covers both. */
const isSilent = computed<boolean>(() => isMuted.value || volume.value === 0);

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
 * Push the level onto the element.
 *
 * `volume` and `muted` are properties of the ELEMENT, not of its source, so they
 * survive every `src` change and this needs calling only when one of them changes or
 * a new element is attached — not per track.
 */
function applyVolume(): void {
    if (!element) return;

    element.volume = volume.value;
    element.muted = isMuted.value;
}

/** Write the level down. Failure is silent: a working player matters more than a remembered level. */
function persistVolume(): void {
    try {
        window.localStorage.setItem(VOLUME_STORAGE_KEY, JSON.stringify({ volume: volume.value, muted: isMuted.value }));
    } catch {
        // Storage full or blocked — the level still applies for this page.
    }
}

/**
 * Read the stored level, once.
 *
 * Every field is re-validated rather than trusted: this value is assigned straight to
 * `element.volume`, which THROWS on anything outside 0–1, so a hand-edited or
 * half-written entry would otherwise break playback at attach time rather than
 * degrade. A number that fails the check is simply not adopted.
 */
function hydrateVolume(): void {
    if (volumeHydrated) return;
    volumeHydrated = true;

    let stored: string | null = null;
    try {
        stored = window.localStorage.getItem(VOLUME_STORAGE_KEY);
    } catch {
        return; // Storage unavailable; the default level is fine.
    }
    if (!stored) return;

    try {
        const payload = JSON.parse(stored) as { volume?: unknown; muted?: unknown };

        if (typeof payload.volume === "number" && Number.isFinite(payload.volume)) {
            volume.value = Math.min(Math.max(payload.volume, 0), 1);
            if (volume.value > 0) levelBeforeMute = volume.value;
        }
        if (typeof payload.muted === "boolean") isMuted.value = payload.muted;
    } catch {
        // Corrupt entry — start at full rather than throw at boot.
    }
}

/**
 * Tell the OS what is playing, so the lock screen, the notification shade and a
 * keyboard's media keys all show the right thing.
 *
 * Plain browser API, no dependency — and the reason the player needs no library at
 * all for background playback. Guarded because Media Session is absent on desktop
 * Firefox and older WebViews, where a missing lock-screen title is the only cost.
 */
function publishMetadata(track: QueueTrack | null): void {
    if (!("mediaSession" in navigator)) return;

    if (!track) {
        navigator.mediaSession.metadata = null;

        return;
    }

    navigator.mediaSession.metadata = new MediaMetadata({
        title: track.name,
        artist: track.artist ?? "",
        album: track.album ?? "",
        artwork: track.coverUrl ? [{ src: track.coverUrl, type: "image/jpeg" }] : []
    });
}

/**
 * Publish the cursor and total to the OS, so a lock screen can draw its own
 * progress bar and scrub.
 *
 * Wrapped in try/catch on purpose: `setPositionState` throws a TypeError when the
 * position runs past the duration, and the two come from different places here (the
 * database's measured length, the element's real playhead). A file whose tags
 * disagree with its bytes must cost a missing lock-screen scrubber, not a broken
 * player.
 */
function publishPositionState(): void {
    if (!("mediaSession" in navigator) || typeof navigator.mediaSession.setPositionState !== "function") return;

    const total = duration.value;
    if (!Number.isFinite(total) || total <= 0) return;

    try {
        navigator.mediaSession.setPositionState({
            duration: total,
            position: Math.min(Math.max(currentTime.value, 0), total),
            playbackRate: element?.playbackRate ?? 1
        });
    } catch {
        // Position outside the claimed duration — nothing here depends on it.
    }
}

/** Mirror the play state to the OS, so a lock-screen button shows the right glyph. */
function publishPlaybackState(): void {
    if (!("mediaSession" in navigator)) return;

    navigator.mediaSession.playbackState = isPlaying.value ? "playing" : "paused";
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
    }

    publishMetadata(track);

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
    publishMetadata(null);
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
        if (!element) return;

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
     * Set the output level.
     *
     * Dragging up LIFTS A MUTE, deliberately: the slider is the more specific gesture
     * of the two, and a slider that visibly moves while the player stays silent is the
     * kind of control people press twice and then distrust. Zero is left as a level in
     * its own right rather than turned into a mute — `isSilent` is what collapses the
     * distinction for the glyph, so the two states stay separately undoable.
     */
    function setVolume(value: number): void {
        volume.value = Math.min(Math.max(value, 0), 1);

        if (volume.value > 0) {
            levelBeforeMute = volume.value;
            isMuted.value = false;
        }

        applyVolume();
        persistVolume();
    }

    /**
     * Mute, or come back from it.
     *
     * Un-muting from a level of zero lands on the remembered level instead, because
     * `muted = false` over `volume = 0` is still silence — the press would appear to
     * do nothing at all.
     */
    function toggleMute(): void {
        if (isMuted.value) {
            isMuted.value = false;
            if (volume.value === 0) volume.value = levelBeforeMute > 0 ? levelBeforeMute : FULL_VOLUME;
        } else {
            if (volume.value > 0) levelBeforeMute = volume.value;
            isMuted.value = true;
        }

        applyVolume();
        persistVolume();
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
        detach();
        element = audio;

        // Before anything can be heard: a fresh element starts at unity regardless of
        // what the listener chose last visit.
        hydrateVolume();
        applyVolume();

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
        // rather than sitting on pause over silence.
        on("error", () => {
            isPlaying.value = false;
            publishPlaybackState();
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
         * OS transport controls — lock screen, notification shade, media keys, a car
         * head unit. These are the handlers that make the queue work when the page is
         * not on screen at all, which is the whole point of wiring Media Session.
         */
        if ("mediaSession" in navigator) {
            const actions: Array<[MediaSessionAction, () => void]> = [
                ["play", play],
                ["pause", pause],
                ["stop", pause],
                ["previoustrack", () => queue.previous()],
                ["nexttrack", () => queue.next()],
                ["seekbackward", () => seek(currentTime.value - 10)],
                ["seekforward", () => seek(currentTime.value + 10)]
            ];

            for (const [action, handler] of actions) {
                try {
                    navigator.mediaSession.setActionHandler(action, handler);
                    teardown.push(() => navigator.mediaSession.setActionHandler(action, null));
                } catch {
                    // An action this browser does not know. The rest still work.
                }
            }

            // `seekto` carries a payload, so it cannot join the list above.
            try {
                navigator.mediaSession.setActionHandler("seekto", details => {
                    if (typeof details.seekTime === "number") seek(details.seekTime);
                });
                teardown.push(() => navigator.mediaSession.setActionHandler("seekto", null));
            } catch {
                // Not supported here; the lock screen simply offers no scrubber.
            }
        }

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

        // A queue restored from storage, or one built before the bar mounted. Loaded so
        // the timeline can draw its total and the first press starts instantly — but
        // NOT played: page load is not a user gesture.
        if (queue.current.value) load(queue.current.value, false);
        publishPlaybackState();
    }

    /** Drop every listener and forget the element. */
    function detach(): void {
        for (const undo of teardown) undo();
        teardown = [];
        element = null;
        isPlaying.value = false;
        currentTime.value = 0;
        buffered.value = [];
        elementDuration.value = 0;
    }

    return {
        isPlaying,
        currentTime,
        duration,
        buffered,
        attach,
        detach,
        play,
        pause,
        toggle,
        seek,
        volume,
        isMuted,
        isSilent,
        setVolume,
        toggleMute
    };
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
    isPlaying.value = false;
    currentTime.value = 0;
    buffered.value = [];
    elementDuration.value = 0;

    // The level too, and its hydration latch — a spec that turns the volume down would
    // otherwise leave the next one starting quiet, and one that seeds localStorage would
    // find hydration already spent. `detach()` deliberately does NOT do this: unmounting
    // the bar when the queue empties must not forget a preference.
    volume.value = FULL_VOLUME;
    isMuted.value = false;
    levelBeforeMute = FULL_VOLUME;
    volumeHydrated = false;
}
