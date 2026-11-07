import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerAudioForTests } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import PartyMode from "./PartyMode.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The party overlay's own behaviour — everything about it that is not a picture.
 *
 * The picture, real fullscreen and the analyser need a browser and a GPU and are proven in
 * now-playing.spec.ts. What this layer can hold the overlay to:
 *
 *   - IT NAMES THE SAME THREE TRACKS AS THE PAGE, from the live queue, and the entries drive it.
 *   - EVERY WAY OUT ASKS THE PAGE TO CLOSE IT: the button, Escape, and leaving fullscreen by the
 *     browser's own route — the last of which is the one a reader actually uses, since Escape in
 *     fullscreen never reaches the page.
 *   - THE PAGE BEHIND IS INERT WHILE IT IS OPEN, AND ONLY UNTIL IT CLOSES. Getting the second half
 *     wrong leaves the whole app unclickable after a party — the worst bug this component can have.
 *   - THE WAKE LOCK IS TAKEN AND GIVEN BACK.
 *   - NO WEBGL IS NOT AN ERROR: happy-dom has none, which is exactly the case to prove — the
 *     party opens, without a picture, rather than throwing.
 */

/** A queue track by id. */
const track = (id: string): QueueTrack => ({
    id,
    name: `Track ${id}`,
    artist: "Sigur Rós",
    album: "Takk…",
    coverUrl: null,
    duration: 200,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** Mount the overlay over a queue of a, b, c with b loaded. */
const open = () => {
    usePlayerQueue().playNow([track("a"), track("b"), track("c")], 1);

    return mountApp(PartyMode, { props: { genres: { b: "Post-Rock" } } });
};

/** The overlay, wherever the teleport put it. */
const overlay = (): HTMLElement => document.querySelector<HTMLElement>(".party")!;

describe("PartyMode", () => {
    let fullscreenElement: Element | null;
    let released: number;
    let requested: number;

    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetPlayerAudioForTests();
        vi.restoreAllMocks();

        fullscreenElement = null;
        released = 0;
        requested = 0;
        Object.defineProperty(document, "fullscreenElement", {
            configurable: true,
            get: () => fullscreenElement
        });
        Object.defineProperty(document, "exitFullscreen", {
            configurable: true,
            value: () => {
                fullscreenElement = null;
                document.dispatchEvent(new Event("fullscreenchange"));

                return Promise.resolve();
            }
        });
        Object.defineProperty(HTMLElement.prototype, "requestFullscreen", {
            configurable: true,
            value(this: HTMLElement) {
                fullscreenElement = document.querySelector(".party");
                document.dispatchEvent(new Event("fullscreenchange"));

                return Promise.resolve();
            }
        });
        Object.defineProperty(navigator, "wakeLock", {
            configurable: true,
            value: {
                request: () => {
                    requested += 1;
                    const sentinel = new EventTarget() as EventTarget & { release: () => Promise<void> };
                    sentinel.release = () => {
                        released += 1;

                        return Promise.resolve();
                    };

                    return Promise.resolve(sentinel);
                }
            }
        });
    });

    afterEach(() => {
        Reflect.deleteProperty(document, "fullscreenElement");
        Reflect.deleteProperty(document, "exitFullscreen");
        Reflect.deleteProperty(HTMLElement.prototype, "requestFullscreen");
        Reflect.deleteProperty(navigator, "wakeLock");
        document.body.innerHTML = "";
    });

    it("names the previous, current and next tracks from the live queue", () => {
        open();

        const titles = Array.from(document.querySelectorAll(".party-track__title")).map(title => title.textContent);
        expect(titles).toEqual(["Track a", "Track b", "Track c"]);
        // The page's genre reaches the entry it belongs to, and only that one.
        expect(document.querySelector(".party-track--current")?.textContent).toContain("Post-Rock");
        expect(document.querySelector(".party-track--next")?.textContent).not.toContain("Post-Rock");
    });

    it("steps the player from the neighbour entries", async () => {
        open();

        document.querySelector<HTMLButtonElement>(".party-track--next .party-track__action")!.click();
        await nextTick();

        expect(usePlayerQueue().current.value?.id).toBe("c");
        expect(document.querySelector(".party-track--current .party-track__title")?.textContent).toBe("Track c");
    });

    it("goes fullscreen on opening, and closes when fullscreen is left by any route", async () => {
        const wrapper = open();
        await vi.waitFor(() => expect(fullscreenElement).toBe(overlay()));

        // The browser's own Escape (or its UI) — the page never hears the key, only this event.
        fullscreenElement = null;
        document.dispatchEvent(new Event("fullscreenchange"));

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("closes on Escape when the browser kept it in a window", async () => {
        Object.defineProperty(HTMLElement.prototype, "requestFullscreen", {
            configurable: true,
            value: () => Promise.reject(new Error("refused"))
        });
        const wrapper = open();
        await nextTick();

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("closes from its own button", () => {
        const wrapper = open();

        document.querySelector<HTMLButtonElement>(".party__exit")!.click();

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("makes the page behind inert while open, and gives back exactly what it took", () => {
        const page = document.createElement("div");
        const alreadyInert = document.createElement("div");
        alreadyInert.inert = true;
        document.body.append(page, alreadyInert);

        const wrapper = open();
        expect(page.inert).toBe(true);
        expect(overlay().inert).toBe(false);

        wrapper.unmount();
        expect(page.inert).toBe(false);
        // Something else made this inert; ending the party must not wake it up.
        expect(alreadyInert.inert).toBe(true);
    });

    it("keeps the screen awake for as long as it is open", async () => {
        const wrapper = open();
        await vi.waitFor(() => expect(requested).toBe(1));

        wrapper.unmount();
        await vi.waitFor(() => expect(released).toBe(1));
    });

    it("opens without a picture where there is no WebGL2, rather than failing", async () => {
        open();

        await vi.waitFor(() => expect(overlay().dataset.visualizer).toBe("unsupported"));
        expect(document.querySelectorAll(".party-track")).toHaveLength(3);
    });

    it("offers no visualiser switch while there is only one visualiser", () => {
        open();

        expect(document.querySelectorAll(".party__control")).toHaveLength(1);
    });
});

describe("PartyMode and the page's own shortcuts", () => {
    /*
     * `Q`, `/` and ⌘K open popovers that live in the page behind — which is inert, and `inert` does
     * not stop `showPopover()`. So while a party is open those three must never reach their
     * handlers, or a popover opens over the party that cannot take focus. Everything else must
     * still get through: Space is how a reader pauses a party.
     */
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetPlayerAudioForTests();
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("keeps Q, / and ⌘K from the page's handlers, and lets the player's keys through", () => {
        usePlayerQueue().playNow([track("a")]);
        const heard: string[] = [];
        const listener = (event: KeyboardEvent): void => {
            heard.push(event.key);
        };
        window.addEventListener("keydown", listener);

        const wrapper = mountApp(PartyMode, { props: { genres: {} } });
        // Dispatched where a real press lands — the focused overlay — and bubbling, so the event
        // travels the window's capture phase first, as it does in a browser.
        for (const init of [{ key: "q" }, { key: "Q" }, { key: "/" }, { key: "k", metaKey: true }, { key: " " }]) {
            overlay().dispatchEvent(new KeyboardEvent("keydown", { ...init, bubbles: true }));
        }
        expect(heard).toEqual([" "]);

        // And only for as long as the party is open.
        wrapper.unmount();
        document.body.dispatchEvent(new KeyboardEvent("keydown", { key: "/", bubbles: true }));
        expect(heard).toEqual([" ", "/"]);

        window.removeEventListener("keydown", listener);
    });
});
