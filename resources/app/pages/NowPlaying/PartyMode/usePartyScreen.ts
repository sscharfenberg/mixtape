/******************************************************************************
 * usePartyScreen
 * Everything party mode asks of the SCREEN rather than of the music: fullscreen, keeping the
 * display awake, shutting the page behind it out of focus, and hiding the pointer when nobody is
 * moving it.
 *
 * FULLSCREEN IS AN ENHANCEMENT, NOT A DEPENDENCY. The overlay is already a fixed layer over the
 * whole viewport; fullscreen only takes the browser's own chrome away. Where it is refused — no
 * user activation, a browser that only lets `<video>` go fullscreen (iPhone Safari), an embedding
 * that forbids it — the party simply runs in the window.
 *
 * LEAVING FULLSCREEN LEAVES THE PARTY. Escape exits fullscreen in the browser itself, before the
 * page sees the key, so `fullscreenchange` is the only place that press can be heard — and a
 * reader who pressed Escape meant "stop", not "show me the tunnel in a window". Outside
 * fullscreen, Escape is heard directly and does the same.
 *
 * THE WAKE LOCK IS THE DIFFERENCE BETWEEN A PARTY AND A LOCK SCREEN. A phone left on a speaker
 * dims and locks after half a minute without it. The browser releases the lock whenever the tab
 * is hidden, so it is taken again each time the tab comes back.
 *
 * THE PAGE BEHIND IS MADE `inert`. In fullscreen the browser does that itself — Chromium treats
 * everything outside a fullscreen element as inert, measured: a `focus()` sent there is refused —
 * but the windowed fallback has no such help, and there Tab would walk into the page under the
 * overlay. So every sibling of the overlay gets `inert` for as long as the party lasts, and
 * exactly those siblings lose it afterwards. The browser's half has a consequence on the way out,
 * noted where focus is handed back.
 *
 * …AND THE KEYS THAT OPEN THE PAGE'S OWN UI STAND DOWN. `Q` (the queue panel), `/` and ⌘K
 * (search) are bound on the window and open popovers that live in the inert page — and `inert`
 * does not stop `showPopover()`, so they would land in the top layer OVER the party, unusable: the
 * search field cannot take focus, every letter typed falls through to the player's keymap (`n`
 * skips a track), and the Escape that closes the popover ends the party with it. A capture
 * listener on the window swallows exactly those keys while the party is open; everything else —
 * Space, the arrows, the transport letters — still reaches the player.
 *****************************************************************************/
import type { Ref } from "vue";
import { onBeforeUnmount, onMounted, ref } from "vue";

/** How long without a pointer move or a key before the pointer and the controls fade, in ms. */
export const IDLE_AFTER_MS = 3000;

/**
 * Whether a key press would open UI that lives in the page behind the party — the queue panel's
 * `Q`, and search's `/` and ⌘K / Ctrl+K. Exported for the tests, which pin the list.
 */
export function opensPageUi(event: KeyboardEvent): boolean {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") return true;
    if (event.metaKey || event.ctrlKey || event.altKey) return false;

    return event.key === "/" || event.key.toLowerCase() === "q";
}

/** What {@link usePartyScreen} hands back. */
export type UsePartyScreenReturn = {
    /** True once the overlay is actually fullscreen — false where the browser refused. */
    fullscreen: Ref<boolean>;
    /** True after {@link IDLE_AFTER_MS} without input: the pointer hides and the controls fade. */
    idle: Ref<boolean>;
    /** Note some input — un-hides the pointer and restarts the idle clock. */
    wake: () => void;
};

/**
 * Take over the screen for the overlay at `root`, for as long as the calling component is mounted,
 * calling `leave` when the reader asks to go (Escape, or leaving fullscreen by any route).
 *
 * MUST BE MOUNTED FROM A USER'S PRESS: `requestFullscreen` needs the transient activation a click
 * leaves behind, and it is called in `onMounted`, which runs in the same task as the click that
 * rendered the overlay.
 */
export function usePartyScreen(root: Ref<HTMLElement | null>, leave: () => void): UsePartyScreenReturn {
    const fullscreen = ref(false);
    const idle = ref(false);

    let opener: HTMLElement | null = null;
    let inerted: HTMLElement[] = [];
    let lock: WakeLockSentinel | null = null;
    let idleTimer: ReturnType<typeof setTimeout> | null = null;
    let mounted = false;

    /** Restart the idle clock and show the pointer again. */
    const wake = (): void => {
        idle.value = false;
        if (idleTimer !== null) clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            idle.value = true;
        }, IDLE_AFTER_MS);
    };

    /**
     * Ask for the screen to stay on. Quietly does nothing where the API is missing or refuses (a
     * battery saver may) — a party that lets the screen sleep is a lesser party, not a broken one.
     */
    const acquireLock = async (): Promise<void> => {
        if (!("wakeLock" in navigator) || lock !== null) return;

        try {
            const sentinel = await navigator.wakeLock.request("screen");
            // Unmounted while the request was in flight: give it straight back.
            if (!mounted) {
                void sentinel.release();

                return;
            }
            lock = sentinel;
            lock.addEventListener("release", () => {
                lock = null;
            });
        } catch {
            lock = null;
        }
    };

    /** Take the lock again when the tab comes back — the browser dropped it when it was hidden. */
    const onVisibility = (): void => {
        if (document.visibilityState === "visible") void acquireLock();
    };

    /** Leaving fullscreen by any route — Escape, the browser's own UI, a gesture — ends the party. */
    const onFullscreenChange = (): void => {
        const ours = root.value !== null && document.fullscreenElement === root.value;
        if (fullscreen.value && !ours) {
            fullscreen.value = false;
            leave();
        }
    };

    /** Escape outside fullscreen, where the page hears it itself. */
    const onKeydown = (event: KeyboardEvent): void => {
        if (event.key === "Escape" && !event.defaultPrevented) leave();
    };

    /**
     * Swallow the keys that would open the page's own popovers over the party — see the banner.
     * Capture phase on the window, so it runs before any listener that would act on them, and
     * `stopPropagation` as well as `preventDefault`, because ⌘K's handler does not consult
     * `defaultPrevented`.
     */
    const onShortcut = (event: KeyboardEvent): void => {
        if (!opensPageUi(event)) return;
        event.preventDefault();
        event.stopPropagation();
    };

    onMounted(() => {
        mounted = true;
        const overlay = root.value;
        if (overlay === null) return;

        opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        inerted = Array.from(document.body.children).filter(
            (child): child is HTMLElement => child instanceof HTMLElement && child !== overlay && !child.inert
        );
        for (const child of inerted) child.inert = true;

        // Focus the overlay ITSELF rather than its first button: Space is the player's
        // play/pause key, and with the exit button focused the first press of it would end the
        // party instead.
        overlay.focus();

        document.addEventListener("fullscreenchange", onFullscreenChange);
        document.addEventListener("keydown", onKeydown);
        document.addEventListener("visibilitychange", onVisibility);
        window.addEventListener("keydown", onShortcut, { capture: true });

        if (typeof overlay.requestFullscreen === "function") {
            overlay
                .requestFullscreen({ navigationUI: "hide" })
                .then(() => {
                    fullscreen.value = document.fullscreenElement === overlay;
                })
                .catch(() => {
                    fullscreen.value = false;
                });
        }

        void acquireLock();
        wake();
    });

    onBeforeUnmount(() => {
        mounted = false;
        document.removeEventListener("fullscreenchange", onFullscreenChange);
        document.removeEventListener("keydown", onKeydown);
        document.removeEventListener("visibilitychange", onVisibility);
        window.removeEventListener("keydown", onShortcut, { capture: true });
        if (idleTimer !== null) clearTimeout(idleTimer);

        void lock?.release().catch(() => undefined);
        lock = null;

        for (const child of inerted) child.inert = false;
        inerted = [];

        // Back to the button that opened the party, so a keyboard reader lands where they left —
        // but only once fullscreen has actually ENDED. While an element is fullscreen the browser
        // treats the rest of the document as inert in its own right (whatever this file did), so
        // a focus() sent before the exit completes is silently refused and focus falls to <body>.
        const target = opener;
        /** Focus the opener, if it is still on the page (navigating away removes it). */
        const restoreFocus = (): void => {
            if (target?.isConnected) target.focus();
        };

        if (root.value !== null && document.fullscreenElement === root.value) {
            document.exitFullscreen().then(restoreFocus, restoreFocus);
        } else {
            restoreFocus();
        }
    });

    return { fullscreen, idle, wake };
}
