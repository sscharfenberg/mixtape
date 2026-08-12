import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayerShortcuts } from "Composables/usePlayerShortcuts";
import { resetPlayerSpeedForTests, usePlayerSpeed } from "Composables/usePlayerSpeed";
import { usePlayerVolume } from "Composables/usePlayerVolume";
import {
    notePlayQueuePanel,
    resetPlayQueuePanelForTests,
    usePlayQueuePanel
} from "Composables/usePlayQueuePanel";
import { resetInertia } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Keyboard control of the player. A document-wide key listener is a claim on keys the rest
 * of the page also wants, so most of this file is about the claim being GIVEN BACK — which
 * is exactly the half that has no visible symptom until someone hits it.
 *
 * The guards, in the order they would hurt:
 *
 *   - A SPACE IN A PASSWORD must not pause the music. The reader would have no way to
 *     connect the two, and every form in this app has a password field with a space
 *     legitimately in it. Same for a letter: M in a passphrase is the identical bug.
 *   - SPACE ON A FOCUSED BUTTON is the half a "not while typing" rule misses. Space
 *     ACTIVATES a focused button, so submitting a form with the keyboard would also toggle
 *     playback. The arrows are worse — a range input, a radio group and TabbedNavigation's
 *     tabs all drive off them.
 *   - A SECOND BIND must not double every shortcut. `next` would skip two tracks.
 *
 * And the hold, which is the one piece of real logic here rather than a mapping:
 *
 *   - a TAP toggles, on key-UP. It cannot toggle on key-down, because a hold is not
 *     recognisable until it has lasted a while and every skim would then begin by pausing
 *     the track it wants to skim through.
 *   - a HOLD skims and its release does NOT toggle. The skim is RELATIVE to the chosen
 *     speed, so a listener at 3× skims at 6× and lands back on 3× rather than on 1×.
 *   - a hold on a PAUSED player engages nothing, and still plays on release.
 *   - THE KEY-UP CAN GO MISSING. Switch windows mid-hold and the release is delivered
 *     somewhere else, so blur and visibilitychange have to end the skim or the track is
 *     stuck fast with no key down — unrecoverable except by pressing Space again.
 *
 * The composable is driven against the REAL player singletons rather than mocks: what is
 * being tested is that a key reaches the right one of them, and a mocked `usePlayerAudio`
 * would assert the wiring of the mock. `attach` is given a real happy-dom <audio>, which is
 * enough for `playbackRate` (docs/testing.md → traps).
 */

/** The one element the player owns, recreated per test so no state carries over. */
let audioElement: HTMLAudioElement;

/** Bind/unbind pair under test. */
let shortcuts: ReturnType<typeof usePlayerShortcuts>;

/** A queue entry. */
const track = (id: string) => ({
    id,
    name: `Track ${id}`,
    artist: "Radiohead",
    album: "OK Computer",
    href: `/music/songs/${id}`,
    coverUrl: null,
    streamUrl: `/music/songs/${id}/stream`,
    duration: 300
});

/**
 * Press a key at `target`, defaulting to the body.
 *
 * Dispatched on the element rather than through `window` directly, because the guards read
 * `event.target` — a synthetic event fired at `window` has no target to judge, which is the
 * one thing that would make every guard pass vacuously.
 */
const press = (key: string, options: KeyboardEventInit = {}, target: EventTarget = document.body): KeyboardEvent => {
    const event = new window.KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...options });
    target.dispatchEvent(event);

    return event;
};

/** Release a key at `target`. */
const release = (key: string, target: EventTarget = document.body): void => {
    target.dispatchEvent(new window.KeyboardEvent("keyup", { key, bubbles: true, cancelable: true }));
};

/** Hold Space long enough for the skim to engage. */
const holdSpace = async (): Promise<void> => {
    press(" ");
    await vi.advanceTimersByTimeAsync(200);
};

describe("usePlayerShortcuts", () => {
    beforeEach(() => {
        resetInertia();
        vi.useFakeTimers();
        localStorage.clear();
        document.body.innerHTML = "";

        const queue = usePlayerQueue();
        queue.clear();
        /*
         * `clear()` empties the queue but deliberately does NOT reset these two — they are
         * playback preferences rather than queue contents. That makes them module state
         * surviving every test in this file, and one of the tests below turns both on: with
         * shuffle left on, `next()` follows the shuffle walk instead of the visible order,
         * so a later `currentIndex === 1` assertion is passing on luck. Reset explicitly.
         */
        queue.shuffle.value = false;
        queue.repeat.value = false;

        audioElement = document.createElement("audio");
        document.body.append(audioElement);
        // happy-dom has no decoder, so `play()` resolves without one — enough for the
        // intent flag, which is all the hold logic reads.
        audioElement.play = vi.fn().mockResolvedValue(undefined);
        audioElement.pause = vi.fn();

        // Module state like the queue's own, so the panel a Q test opened would otherwise
        // still be open in the next file that asks.
        resetPlayQueuePanelForTests();
        resetPlayerSpeedForTests();
        usePlayerAudio().attach(audioElement);
        usePlayerVolume().setVolume(0.5);

        queue.playNow([track("a"), track("b"), track("c")]);

        shortcuts = usePlayerShortcuts();
        shortcuts.bind();
    });

    afterEach(() => {
        shortcuts.unbind();
        usePlayerAudio().detach();
        usePlayerQueue().clear();
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    describe("the guards", () => {
        it("leaves a space typed into a password alone, so a passphrase cannot pause the music", () => {
            const field = document.createElement("input");
            field.type = "password";
            document.body.append(field);
            const before = usePlayerAudio().isPlaying.value;

            const event = press(" ", {}, field);
            release(" ", field);

            expect(usePlayerAudio().isPlaying.value).toBe(before);
            // …and the space still reaches the field, rather than being swallowed.
            expect(event.defaultPrevented).toBe(false);
        });

        it("leaves the letter shortcuts alone in a text field too", () => {
            // "m" in a passphrase is the same bug as a space in one.
            const field = document.createElement("input");
            field.type = "text";
            document.body.append(field);

            press("m", {}, field);

            expect(usePlayerVolume().isMuted.value).toBe(false);
        });

        it("lets a focused BUTTON keep Space, which is what activates it", () => {
            // The half a "not while typing" rule misses: submitting a form with the
            // keyboard would otherwise toggle playback as well.
            const button = document.createElement("button");
            document.body.append(button);
            const before = usePlayerAudio().isPlaying.value;

            press(" ", {}, button);
            release(" ", button);

            expect(usePlayerAudio().isPlaying.value).toBe(before);
        });

        it("lets a focused slider keep the arrow keys", () => {
            // The volume rail and the timeline are both <input type="range">.
            const slider = document.createElement("input");
            slider.type = "range";
            document.body.append(slider);

            press("ArrowUp", {}, slider);

            expect(usePlayerVolume().volume.value).toBe(0.5);
        });

        it("lets a tab strip keep its arrows, matched by role rather than by tag", () => {
            // TabbedNavigation's tabs are buttons with role="tab"; the Select's options are
            // buttons too. A tag check sees only "a button", which is true and useless.
            const tab = document.createElement("button");
            tab.setAttribute("role", "tab");
            document.body.append(tab);

            press("ArrowRight", {}, tab);

            expect(usePlayerAudio().currentTime.value).toBe(0);
        });

        it("stands aside for a key a component already handled", () => {
            const event = new window.KeyboardEvent("keydown", { key: "n", bubbles: true, cancelable: true });
            event.preventDefault();
            document.body.dispatchEvent(event);

            expect(usePlayerQueue().currentIndex.value).toBe(0);
        });

        it("leaves the browser's own and the queue's own modifier combinations alone", () => {
            // Ctrl/Cmd belong to the browser; Alt+arrows are the queue's reorder gesture.
            press("n", { ctrlKey: true });
            press("n", { metaKey: true });
            press("ArrowUp", { altKey: true });

            expect(usePlayerQueue().currentIndex.value).toBe(0);
            expect(usePlayerVolume().volume.value).toBe(0.5);
        });

        it("stops the page scrolling under the keys it does claim", () => {
            expect(press(" ").defaultPrevented).toBe(true);
            expect(press("ArrowDown").defaultPrevented).toBe(true);
            // …and does not preventDefault a key it does not handle.
            expect(press("q").defaultPrevented).toBe(false);
        });
    });

    describe("the transport", () => {
        it("toggles play on a tap of Space, when the key comes UP", async () => {
            usePlayerAudio().pause();

            press(" ");
            expect(usePlayerAudio().isPlaying.value).toBe(false); // nothing yet — that is the design

            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(usePlayerAudio().isPlaying.value).toBe(true);
        });

        it("steps the queue with Shift and an arrow, and seeks with a bare one", () => {
            press("ArrowRight", { shiftKey: true });
            expect(usePlayerQueue().currentIndex.value).toBe(1);

            press("ArrowLeft", { shiftKey: true });
            expect(usePlayerQueue().currentIndex.value).toBe(0);
        });

        it("seeks by five seconds, and clamps at the start rather than going negative", () => {
            press("ArrowRight");
            expect(usePlayerAudio().currentTime.value).toBe(5);

            press("ArrowLeft");
            expect(usePlayerAudio().currentTime.value).toBe(0);

            press("ArrowLeft");
            expect(usePlayerAudio().currentTime.value).toBe(0);
        });

        it("moves the volume with the up and down arrows, and clamps at full", () => {
            // 5% a press — twenty steps across the scale, and the same figure the slider's
            // own `step` carries, since a focused slider handles these keys itself.
            press("ArrowUp");
            expect(usePlayerVolume().volume.value).toBeCloseTo(0.55);

            press("ArrowDown");
            expect(usePlayerVolume().volume.value).toBeCloseTo(0.5);

            usePlayerVolume().setVolume(1);
            press("ArrowUp");
            expect(usePlayerVolume().volume.value).toBe(1);
        });

        it("offers the letter aliases for a keyboard that would rather not reach for Space", async () => {
            usePlayerAudio().pause();

            press("k");
            await vi.advanceTimersByTimeAsync(0);
            expect(usePlayerAudio().isPlaying.value).toBe(true);

            press("l");
            expect(usePlayerAudio().currentTime.value).toBe(5);
            press("j");
            expect(usePlayerAudio().currentTime.value).toBe(0);

            press("n");
            expect(usePlayerQueue().currentIndex.value).toBe(1);
            press("p");
            expect(usePlayerQueue().currentIndex.value).toBe(0);
        });

        it("mutes, shuffles and repeats from their own letters", () => {
            press("m");
            expect(usePlayerVolume().isMuted.value).toBe(true);

            press("s");
            expect(usePlayerQueue().shuffle.value).toBe(true);

            press("r");
            expect(usePlayerQueue().repeat.value).toBe(true);
        });

        it("answers a shifted letter the same way, since Shift is not part of those keys", () => {
            // Caps lock, or a hand still on Shift from stepping a track.
            press("M", { shiftKey: true });

            expect(usePlayerVolume().isMuted.value).toBe(true);
        });

        it("shows and hides the queue panel from Q — the one key here that moves no audio", () => {
            // A panel has to be on the page for the key to have anything to flip; PlayQueue
            // registers itself when it mounts, and here that is stated rather than mounted.
            notePlayQueuePanel(true);
            expect(usePlayQueuePanel().isOpen.value).toBe(false);

            press("q");
            expect(usePlayQueuePanel().isOpen.value).toBe(true);

            press("Q", { shiftKey: true });
            expect(usePlayQueuePanel().isOpen.value).toBe(false);
        });

        it("leaves Q inert where no panel is rendered, which is the guest share space", () => {
            // The bar and these listeners live there too — it is only the PANEL that is absent
            // — so without the guard this would flip a flag nothing reads, invisibly.
            press("q");

            expect(usePlayQueuePanel().isOpen.value).toBe(false);
        });

        it("leaves Q alone in a text field, or a queue name could not be typed", () => {
            const input = document.createElement("input");
            document.body.append(input);

            press("q", {}, input);

            expect(usePlayQueuePanel().isOpen.value).toBe(false);
        });
    });

    describe("holding Space to skim", () => {
        it("doubles the speed once the key has been down long enough", async () => {
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);

            press(" ");
            expect(usePlayerSpeed().effectiveRate.value).toBe(1); // not yet — this is a tap so far

            await vi.advanceTimersByTimeAsync(200);

            expect(usePlayerSpeed().effectiveRate.value).toBe(2);
            expect(shortcuts.isFastForwarding.value).toBe(true);
            expect(audioElement.playbackRate).toBe(2);
        });

        it("puts the speed back on release, and does NOT also toggle", async () => {
            /*
             * The whole reason the toggle waits for key-up. If the release toggled as well,
             * every skim would end by pausing the track it just raced through.
             */
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);
            await holdSpace();

            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(usePlayerSpeed().effectiveRate.value).toBe(1);
            expect(shortcuts.isFastForwarding.value).toBe(false);
            expect(usePlayerAudio().isPlaying.value).toBe(true);
        });

        it("doubles the CHOSEN speed rather than jumping to an absolute 2×", async () => {
            /*
             * At a 3× setting an absolute skim would SLOW the listener down, and the release
             * would strand them at normal rather than back at what they chose. The key means
             * "faster than this", so it has to be relative to whatever "this" is.
             */
            usePlayerSpeed().setSpeed(3);
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);

            await holdSpace();
            expect(usePlayerSpeed().effectiveRate.value).toBe(6);

            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(usePlayerSpeed().effectiveRate.value).toBe(3);
            expect(usePlayerSpeed().speed.value).toBe(3);
        });

        it("keeps the pitch correct, or the skim is unlistenable rather than merely quick", async () => {
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);
            await holdSpace();

            expect(audioElement.preservesPitch).toBe(true);
        });

        it("engages nothing on a paused player, and still plays when the key comes up", async () => {
            // Holding a key on something making no sound does not mean "start it fast".
            usePlayerAudio().pause();

            await holdSpace();
            expect(shortcuts.isFastForwarding.value).toBe(false);
            expect(usePlayerSpeed().effectiveRate.value).toBe(1);

            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(usePlayerAudio().isPlaying.value).toBe(true);
        });

        it("survives the auto-repeat a held key produces, and leaves nothing running after", async () => {
            /*
             * A real hold is not one keydown: the OS repeats it, so the handler sees a
             * stream of them and a single keyup. This is that sequence end to end — the
             * shape every other test in this block simplifies away.
             *
             * Honest about what it guards: the implementation is redundant here (the
             * `event.repeat || spaceDown` gate means no second timer is ever armed, and the
             * timer's own `spaceDown` check would catch one if it were), so no single
             * mutation of that pair fails this test. It earns its place as the only case
             * that runs the real input sequence, and by draining the timers well past the
             * last deadline — an assertion made immediately after the release would pass
             * whatever a late callback went on to do.
             */
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);

            press(" ");
            await vi.advanceTimersByTimeAsync(150);
            press(" ", { repeat: true });
            await vi.advanceTimersByTimeAsync(60);
            expect(shortcuts.isFastForwarding.value).toBe(true);

            release(" ");
            await vi.advanceTimersByTimeAsync(500);

            expect(shortcuts.isFastForwarding.value).toBe(false);
            expect(usePlayerSpeed().effectiveRate.value).toBe(1);
            expect(audioElement.playbackRate).toBe(1);
        });

        it("ends the skim when the window is taken away mid-hold", async () => {
            /*
             * The key-up is delivered to whatever took focus, so without this the track
             * stays at 2× with no key down — and no way back but pressing Space again.
             */
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);
            await holdSpace();

            window.dispatchEvent(new window.Event("blur"));

            expect(shortcuts.isFastForwarding.value).toBe(false);
            expect(usePlayerSpeed().effectiveRate.value).toBe(1);
        });

        it("ends the skim when the tab is switched away from", async () => {
            // A different failure from blur, and neither implies the other.
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);
            await holdSpace();

            vi.spyOn(document, "visibilityState", "get").mockReturnValue("hidden");
            document.dispatchEvent(new window.Event("visibilitychange"));

            expect(shortcuts.isFastForwarding.value).toBe(false);
            expect(usePlayerSpeed().effectiveRate.value).toBe(1);
        });

        it("ignores a release it never saw the press of", async () => {
            // Hold Space in another window, come back, let go: the up-half arrives alone and
            // must not be read as a tap.
            usePlayerAudio().pause();

            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });
    });

    describe("binding", () => {
        it("does not double up when bound twice, which would skip two tracks at a time", () => {
            shortcuts.bind();

            press("n");

            expect(usePlayerQueue().currentIndex.value).toBe(1);
        });

        it("gives the keys back on unbind, so Space scrolls again once the queue empties", async () => {
            usePlayerAudio().pause();
            shortcuts.unbind();

            const event = press(" ");
            release(" ");
            await vi.advanceTimersByTimeAsync(0);

            expect(event.defaultPrevented).toBe(false);
            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });

        it("leaves nothing running fast when unbound mid-hold", async () => {
            usePlayerAudio().play();
            await vi.advanceTimersByTimeAsync(0);
            await holdSpace();

            shortcuts.unbind();

            expect(usePlayerSpeed().effectiveRate.value).toBe(1);
        });
    });
});
