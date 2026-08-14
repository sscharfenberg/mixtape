/******************************************************************************
 * Global test setup — runs once per test file, before any test.
 *
 * Everything here fills a gap in happy-dom rather than changing app behaviour. Each
 * polyfill is deliberately minimal and honest about being a stand-in: if a test needs
 * more than the real API's basic contract, that is a sign the behaviour belongs in
 * Playwright against a real browser, not in a richer fake.
 *****************************************************************************/
import { enableAutoUnmount } from "@vue/test-utils";
import { afterEach } from "vitest";

/*
 * Unmount every mounted component after each test.
 *
 * Not a nicety — without it, components that watch SHARED state stay alive and keep
 * reacting for the rest of the file. ToastContainer is the example that surfaced it:
 * each mounted instance watches the Inertia page's flash nonce, so by the fifth test
 * five stale containers were each appending a toast to the same singleton list and the
 * assertions counted five toasts instead of one. Teleported markup made it worse, since
 * clearing document.body does not stop the component that keeps re-rendering into it.
 */
enableAutoUnmount(afterEach);

/**
 * A `Storage` implementation, because happy-dom 20 exposes `sessionStorage` but leaves
 * `localStorage` undefined — and useWidgetMode persists every widget's mode through it.
 *
 * The semantics that actually matter to callers are reproduced faithfully: a missing
 * key reads back as `null` (not `undefined`), and values are coerced to strings on
 * write, which is what makes a stored `true` come back as `"true"`.
 */
class MemoryStorage implements Storage {
    private entries = new Map<string, string>();

    /** Number of stored keys, per the Storage interface. */
    get length(): number {
        return this.entries.size;
    }

    /** Read a value, or null when the key was never set. */
    getItem(key: string): string | null {
        return this.entries.get(String(key)) ?? null;
    }

    /** Write a value, coercing it to a string exactly as a real Storage does. */
    setItem(key: string, value: string): void {
        this.entries.set(String(key), String(value));
    }

    /** Drop one key. */
    removeItem(key: string): void {
        this.entries.delete(String(key));
    }

    /** Drop everything — what tests call between cases to isolate state. */
    clear(): void {
        this.entries.clear();
    }

    /** The nth key in insertion order, or null when out of range. */
    key(index: number): string | null {
        return [...this.entries.keys()][index] ?? null;
    }
}

/*
 * Installed unconditionally, and that is deliberate: Node 26 ships its OWN experimental
 * `localStorage` global which is undefined unless `--localstorage-file` was passed, and
 * merely READING it to check prints an ExperimentalWarning — once per worker, so a
 * `typeof globalThis.localStorage` guard here spammed seven warnings per run. Defining
 * over it never reads it, and the app's storage is per-test state anyway.
 */
Object.defineProperty(globalThis, "localStorage", {
    value: new MemoryStorage(),
    writable: true,
    configurable: true
});

/**
 * `document.execCommand` — removed from happy-dom, but useClipboard still calls it on
 * its deprecated fallback path (the branch taken when `navigator.clipboard` is missing, as
 * on older mobile WebViews). Defined as a no-op returning true so the fallback can be
 * exercised at all; a test that cares asserts the surrounding DOM dance, since nothing
 * here can put text on a real clipboard.
 */
if (typeof document !== "undefined" && typeof document.execCommand === "undefined") {
    Object.defineProperty(document, "execCommand", {
        value: () => true,
        writable: true,
        configurable: true
    });
}

/**
 * The Popover API's three methods, which happy-dom does not implement at all.
 *
 * The app leans on it in two places — PopOver for every menu, and PlayQueue for the panel —
 * and PlayQueue calls it straight on a template ref, so without this every test that opens
 * the panel dies on `el.showPopover is not a function`.
 *
 * NO-OPS ON PURPOSE, and the reason is the rule at the top of this file. A popover's whole
 * behaviour is the TOP LAYER — painting order, light dismiss, Escape, the back gesture — and
 * happy-dom has no top layer to put anything in, so a richer fake would only be asserting
 * itself. What the unit tests assert is the app's own state (usePlayQueuePanel's flag, which
 * the panel mirrors from the element's `toggle` event in a real browser); whether the element
 * is genuinely on screen, and whether it dismisses, is the Playwright spec's business.
 *
 * `:popover-open` therefore never matches here, which the component's own guard tolerates: it
 * skips a `hidePopover()` it thinks is unnecessary and calls `showPopover()` more than once,
 * both harmless against a stub.
 */
for (const method of ["showPopover", "hidePopover", "togglePopover"] as const) {
    // HTMLElement, not Element: that is where the API is declared, and where TypeScript's DOM
    // lib knows to find it.
    if (typeof HTMLElement !== "undefined" && typeof HTMLElement.prototype[method] === "undefined") {
        Object.defineProperty(HTMLElement.prototype, method, {
            value: () => {},
            writable: true,
            configurable: true
        });
    }
}
