/******************************************************************************
 * usePlayerShortcuts
 * Keyboard control of the player, bound on the document — so the keys work while you are
 * reading an album page rather than only while the bar has focus, which is the whole point
 * of having them.
 *
 * A document listener is a claim on keys the rest of the page also wants, so nearly all of
 * this file is about GIVING THEM BACK. Four guards, in order of how badly each would bite:
 *
 *   1. TEXT ENTRY. A space in a password would otherwise pause the music, and the reader
 *      would have no idea why. Every field in this app is a real <input> (FormInput renders
 *      one, the OTP field is one, the table search is one), so `isTextEntry` catches them
 *      all — but the letters are guarded by it too, because M in a passphrase is the same
 *      bug wearing a different key.
 *   2. FOCUSED CONTROLS. The subtler half of the same problem, and the one a "not while
 *      typing" rule misses: Space ACTIVATES a focused button and toggles a focused
 *      checkbox, so submitting a form with the keyboard would also pause the music. The
 *      arrows are worse — they drive a range input (this app's volume slider and timeline
 *      both are one), a radio group (the widget mode toggle), and TabbedNavigation's tabs.
 *      Any of those must win; see Utils/interactive for the list, shared with DataTable's
 *      row navigation so the two cannot drift.
 *   3. ALREADY HANDLED. `defaultPrevented` means a component got there first, which is the
 *      general form of guard 2 for anything the selector cannot name.
 *   4. MODIFIERS. Ctrl/Cmd belong to the browser, and Alt is the queue's reorder gesture.
 *      Shift is the exception — it is part of the keymap, not a reason to bail.
 *
 * SPACE IS SPECIAL, and the reason is that one key carries two gestures. A tap toggles
 * play/pause; a HOLD skims until it is released. Since a hold cannot be recognised until it
 * has lasted a while, the toggle has to fire on key-UP — dispatching it on key-down would
 * toggle first and discover the hold afterwards, which means every skim would start by
 * pausing the thing it wants to skim through. The cost is that play/pause lands when you let
 * go instead of when you press; for a real tap that is your own release time and is not
 * perceptible.
 *
 * The skim is RELATIVE, not an absolute 2×, and that only became true once the settings
 * popover could choose a speed: it doubles whatever is set and comes back to it, so a
 * listener at 3× skims at 6× and lands back on 3×. An absolute rate would have meant the
 * key SLOWED them down and then stranded them at normal. usePlayerSpeed owns both numbers;
 * all this file does is say when the skim is on.
 *
 * The shortcuts are only bound while the PlayerBar is mounted, which FullLayout does with
 * `v-if="current"` — so with an empty queue there is no document listener at all and Space
 * scrolls the page exactly as it always did. That is not incidental: an app that quietly
 * stops Space from scrolling, on every page, forever, would be a worse bug than any of the
 * ones above.
 *
 * That scoping is also what lets `Q` live here rather than in a keymap of its own. It shows
 * and hides the QUEUE PANEL — no audio moves — so it is BOUND with the bar and GUARDED on the
 * panel having registered itself. The two are not the same condition: the guest share space
 * keeps the player bar and drops the panel, so a key bound with one has to ask about the other
 * before it does anything (see the guard on `panel.exists` where `Q` is handled).
 *****************************************************************************/
import type { Ref } from "vue";
import { ref } from "vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayerSpeed } from "Composables/usePlayerSpeed";
import { usePlayerVolume } from "Composables/usePlayerVolume";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { isInteractive, isTextEntry } from "Utils/interactive";

/** What a caller gets: the skim flag to draw, and the two lifecycle calls. */
export type UsePlayerShortcutsReturn = {
    /** True while Space is held and the track is running fast — what the bar's 2× badge reads. */
    isFastForwarding: Ref<boolean>;
    /** Start listening. Called by PlayerBar on mount. */
    bind: () => void;
    /** Stop listening and undo any speed-up still in force. */
    unbind: () => void;
};

/** How far ⇦/⇨ (and J/L) move the cursor. Five seconds is a phrase, not a bar. */
const SEEK_STEP_SECONDS = 5;

/**
 * How much ↑/↓ move the output level, as a fraction of full scale.
 *
 * 5%: twenty steps across the scale, which is about what a hardware volume knob offers and
 * more than the sixteen macOS gives its own.
 *
 * EXPORTED BECAUSE IT HAS A TWIN. This constant applies while focus is somewhere ELSE on
 * the page — the guards below deliberately stand aside for a focused range input, so an
 * arrow pressed ON the volume slider belongs to the slider. PlayerVolume therefore takes
 * the arrows itself and steps by THIS number, rather than by the input's own `step`, which
 * is a hundredth so that DRAGGING can land on any percent. One gesture, one answer,
 * whatever happens to have focus — and the two cannot drift, because there is only one of
 * them. The failure when they drift is a listener finding the arrows move the level by 1%
 * inside the popover and 5% everywhere else.
 */
export const VOLUME_STEP = 0.05;

/**
 * How long Space must be held before it means "skim" rather than "toggle".
 *
 * 200 ms is above a deliberate tap and below the shortest press that FEELS like holding.
 * It is also comfortably under the OS key-repeat delay (250 ms at its most aggressive), so
 * the skim starts before the first auto-repeat arrives rather than racing it.
 */
const HOLD_MS = 200;

// Module-level, like every other player singleton here: there is one keyboard, so a second
// set of handlers would run every shortcut twice.
const isFastForwarding = ref<boolean>(false);

/** Pending hold detection, or undefined when Space is not down. */
let holdTimer: ReturnType<typeof setTimeout> | undefined;

/** Whether Space is currently down, so an auto-repeat is not mistaken for a fresh press. */
let spaceDown = false;

/** Everything `bind()` registered, so `unbind()` needs no list of its own. */
let teardown: Array<() => void> = [];

/**
 * Keyboard control of the player. Bound and unbound by PlayerBar, which exists exactly
 * when there is something to control.
 */
export function usePlayerShortcuts(): UsePlayerShortcutsReturn {
    const audio = usePlayerAudio();
    const queue = usePlayerQueue();
    const volume = usePlayerVolume();
    const rate = usePlayerSpeed();
    const panel = usePlayQueuePanel();

    /**
     * Whether this event is the player's to act on.
     *
     * `spaceActivates` widens the guard for the keys a focused CONTROL would consume:
     * Space presses buttons and checkboxes, and the arrows drive sliders, radios and tabs,
     * so for those the whole interactive family is off limits. A letter has no such
     * quarrel — M on a focused button does nothing native — so it only steps aside for
     * text entry, which keeps the letters usable right after clicking something.
     */
    function claims(event: KeyboardEvent, spaceActivates: boolean): boolean {
        if (event.defaultPrevented) return false;
        if (event.ctrlKey || event.metaKey || event.altKey) return false;
        if (isTextEntry(event.target)) return false;
        if (spaceActivates && isInteractive(event.target)) return false;

        return true;
    }

    /** Cancel a pending hold and put the speed back. Safe to call when nothing is held. */
    function endHold(): void {
        clearTimeout(holdTimer);
        holdTimer = undefined;
        spaceDown = false;

        if (isFastForwarding.value) {
            isFastForwarding.value = false;
            // Back to the CHOSEN speed, not to 1 — a listener set to 3× must not be dropped
            // to normal by letting go of a key that only ever meant "faster than this".
            rate.setSkimming(false);
        }
    }

    /**
     * Space went down: arm the hold, and do nothing else yet.
     *
     * The skim engages only if audio is actually RUNNING when the timer fires. Holding
     * Space on a paused player therefore behaves as a slow tap — it plays, on release —
     * rather than starting playback at double speed, which is not what anyone means by
     * holding a key down on something that is not making a sound.
     */
    function onSpaceDown(event: KeyboardEvent): void {
        event.preventDefault(); // the page must not scroll under a held Space

        /*
         * A held key auto-repeats, so this runs many times for one press. Both halves of
         * the test are belt-and-braces rather than load-bearing — `spaceDown` alone already
         * covers the repeat, and the timer below re-checks it anyway — but the alternative
         * is arming a timer per repeat, which is a leak waiting for the first refactor that
         * makes a hold timer do more than set a flag.
         */
        if (event.repeat || spaceDown) return;

        spaceDown = true;
        holdTimer = setTimeout(() => {
            holdTimer = undefined;
            if (!spaceDown || !audio.isPlaying.value) return;

            isFastForwarding.value = true;
            rate.setSkimming(true);
        }, HOLD_MS);
    }

    /**
     * Space came up: either it was a tap, or it was a skim that is now over.
     *
     * The two are mutually exclusive on purpose. A release that ends a skim must NOT also
     * toggle — otherwise every skim would pause the track it just finished racing through,
     * which is both wrong and the sort of thing a listener reads as the app being broken.
     */
    function onSpaceUp(): void {
        const wasSkimming = isFastForwarding.value;

        endHold();
        if (!wasSkimming) audio.toggle();
    }

    /** Step the level and clamp it, so ↑ at full volume is a no-op rather than an error. */
    function nudgeVolume(delta: number): void {
        volume.setVolume(volume.volume.value + delta);
    }

    /** Move the cursor relative to where it is now. */
    function seekBy(seconds: number): void {
        audio.seek(audio.currentTime.value + seconds);
    }

    /**
     * The keymap.
     *
     * Arrow keys carry a second meaning under Shift — a track step rather than a seek —
     * because stepping is the rarer intent and the arrows are the obvious home for both.
     * The letters are aliases for the same actions, for a keyboard (or a listener) that
     * would rather not reach for Space and the arrows at all.
     *
     * …with one exception: `Q` is not an alias for anything, because the queue panel has no
     * other key. It is the only shortcut here that touches the VIEW rather than the audio.
     */
    function onKeydown(event: KeyboardEvent): void {
        // Space and the arrows are the ones a focused control would otherwise consume.
        const guarded = event.key === " " || event.key.startsWith("Arrow");
        if (!claims(event, guarded)) return;

        switch (event.key) {
            case " ":
                onSpaceDown(event);

                return;
            case "ArrowLeft":
                event.preventDefault();
                if (event.shiftKey) queue.previous();
                else seekBy(-SEEK_STEP_SECONDS);

                return;
            case "ArrowRight":
                event.preventDefault();
                if (event.shiftKey) queue.next();
                else seekBy(SEEK_STEP_SECONDS);

                return;
            case "ArrowUp":
                event.preventDefault();
                nudgeVolume(VOLUME_STEP);

                return;
            case "ArrowDown":
                event.preventDefault();
                nudgeVolume(-VOLUME_STEP);

                return;
            default:
                break;
        }

        // Letters, compared lower-case so Shift+M is still mute rather than nothing. Not
        // `event.code`: that is the physical key, which would name the wrong letter on a
        // French or Dvorak layout — the reader presses the letter they can see.
        switch (event.key.toLowerCase()) {
            case "k":
                audio.toggle();
                break;
            case "j":
                seekBy(-SEEK_STEP_SECONDS);
                break;
            case "l":
                seekBy(SEEK_STEP_SECONDS);
                break;
            case "n":
                queue.next();
                break;
            case "p":
                queue.previous();
                break;
            case "m":
                volume.toggleMute();
                break;
            case "s":
                queue.toggleShuffle();
                break;
            case "r":
                queue.toggleRepeat();
                break;
            // The only key here that moves no audio: it shows and hides the QUEUE PANEL, which
            // is the one player surface a listener otherwise has to reach the header for. It
            // belongs in this keymap all the same — `N`/`P` are far more useful once you can see
            // what they are stepping through.
            //
            // GUARDED, because "the panel exists exactly when the bar that binds these listeners
            // does" is NOT true: the guest share space keeps the bar and drops the panel.
            // Unguarded, `Q` there flips a flag nothing reads, which is invisible until something
            // else starts reading it.
            case "q":
                if (panel.exists.value) panel.toggle();
                break;
            default:
                break;
        }
    }

    /** Only Space has an up-half; everything else is done by the time the key rises. */
    function onKeyup(event: KeyboardEvent): void {
        if (event.key !== " ") return;
        if (!spaceDown) return; // the down-half was guarded away, so this is not ours

        event.preventDefault();
        onSpaceUp();
    }

    /**
     * Bind the document listeners.
     *
     * `unbind()` first, so a double bind is impossible — the same guarantee usePlayerAudio's
     * `attach()` gives, and needed for the same reason: two listeners would step every
     * shortcut twice, which on `next` means skipping two tracks.
     */
    function bind(): void {
        unbind();

        const on = <K extends keyof WindowEventMap>(event: K, handler: (payload: WindowEventMap[K]) => void): void => {
            window.addEventListener(event, handler);
            teardown.push(() => window.removeEventListener(event, handler));
        };

        on("keydown", onKeydown);
        on("keyup", onKeyup);

        /*
         * THE KEYUP CAN GO MISSING. Switch windows with Space held — ⌘-Tab, or a click on
         * another app — and the release is delivered to somewhere else entirely, so the
         * skim would never end: the track stays at 2× with no key down and no way to fix it
         * but pressing Space again. Both events are watched because they fail differently:
         * `blur` covers another window taking focus, `visibilitychange` covers the tab being
         * switched away from, and neither implies the other.
         */
        on("blur", endHold);
        const onHidden = (): void => {
            if (document.visibilityState === "hidden") endHold();
        };
        document.addEventListener("visibilitychange", onHidden);
        teardown.push(() => document.removeEventListener("visibilitychange", onHidden));
    }

    /** Drop every listener, and leave the player at normal speed whatever was held. */
    function unbind(): void {
        endHold();
        teardown.forEach(undo => undo());
        teardown = [];
    }

    return { isFastForwarding, bind, unbind };
}
