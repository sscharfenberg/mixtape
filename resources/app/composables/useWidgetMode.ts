import type { Ref } from "vue";
import { ref, watch } from "vue";
import type { WidgetMode } from "Types/music";

/** localStorage key prefix for per-widget mode persistence, namespaced to avoid collisions. */
const STORAGE_PREFIX = "mixtape:widget-mode:";

/**
 * A widget's active mode (latest / popular / random), persisted to localStorage
 * so a chosen mode survives reloads and navigation. Initialised synchronously
 * from the stored value when it's still one of the widget's `allowed` modes (a
 * stale or unsupported entry is ignored), otherwise `fallback`; every change is
 * written back. The app mounts client-side (no SSR), so the read is synchronous
 * and the stored mode renders on first paint — no default-then-swap flash.
 *
 * @param widget    stable per-widget id (e.g. "artists") — the localStorage key suffix
 * @param fallback  the mode to use when nothing valid is stored
 * @param allowed   the modes this widget supports, so a stale stored value is rejected
 */
export const useWidgetMode = (widget: string, fallback: WidgetMode, allowed: WidgetMode[]): Ref<WidgetMode> => {
    const key = STORAGE_PREFIX + widget;

    /** Read + validate the stored mode; fall back when absent, unsupported, or storage is unavailable. */
    const readStored = (): WidgetMode => {
        try {
            const stored = localStorage.getItem(key);
            if (stored !== null && (allowed as string[]).includes(stored)) return stored as WidgetMode;
        } catch {
            // localStorage can throw (privacy mode / disabled) — fall back silently.
        }
        return fallback;
    };

    /** Persist the current mode; storage errors are swallowed so a toggle never breaks. */
    const persist = (value: WidgetMode): void => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // storage unavailable — persistence is best-effort.
        }
    };

    const mode = ref<WidgetMode>(readStored());
    watch(mode, persist);

    return mode;
};
