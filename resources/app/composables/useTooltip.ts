import type { Ref } from "vue";
import { onBeforeUnmount, ref, useId } from "vue";

/** Which side of the trigger the tip sits on — maps 1:1 to a CSS `position-area`. */
export type TooltipPlacement = "top" | "bottom" | "left" | "right";

/** Options accepted by {@link useTooltip}. */
export type UseTooltipOptions = {
    /** ms of hover-intent before a pointer-triggered tip appears; keyboard focus shows it at once. Default 300. */
    showDelay?: number;
};

/** Everything a Tooltip SFC needs to wire a trigger to its floating hint. */
export type UseTooltipReturn = {
    /** Template ref for the popover element holding the tip text. */
    tooltipRef: Ref<HTMLElement | null>;
    /** Stable, SSR-safe id — the tip's `id` and the trigger's optional `aria-describedby`. */
    tooltipId: string;
    /** CSS `anchor-name` (a dashed-ident) unique to this instance, bound into the scoped style. */
    anchorName: string;
    /** Reactive open state, for a caller that wants to mirror it (the tip itself runs off the Popover API). */
    visible: Ref<boolean>;
    /** Reveal the tip; `immediate` skips the hover-intent delay (used on focus). */
    show: (immediate?: boolean) => void;
    /** Hide the tip and cancel any queued reveal. */
    hide: () => void;
};

/**
 * Drives a native-Popover tooltip — hover-intent timing, focus/Escape handling
 * and the top-layer show/hide — kept out of the SFC so the markup stays declarative.
 *
 * The tip is a `popover` element so it lives in the top layer, escaping any
 * `overflow:hidden` / stacking-context ancestor (e.g. the widget frame) exactly
 * like PopOver.vue. Positioning is pure CSS anchor positioning; this composable
 * only decides *when* the tip shows.
 */
export const useTooltip = (options: UseTooltipOptions = {}): UseTooltipReturn => {
    const { showDelay = 300 } = options;

    const tooltipRef = ref<HTMLElement | null>(null);
    const visible = ref(false);

    // useId() is SSR-safe (no hydration mismatch under Inertia SSR) and unique;
    // the anchor-name reuses it, sanitised to a valid CSS dashed-ident.
    const tooltipId = useId();
    const anchorName = `--${tooltipId.replace(/[^\w-]/g, "")}`;

    // Pending hover-intent timer, so a quick pass-through never flashes the tip.
    let timer: ReturnType<typeof setTimeout> | undefined;

    /** Drop any queued reveal so the tip can't appear after the pointer has left. */
    const clearTimer = (): void => {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    };

    /**
     * Promote the tip into the top layer. Guarded on `:popover-open` because
     * `showPopover()` throws if the popover is already open.
     */
    const reveal = (): void => {
        const el = tooltipRef.value;
        if (el && !el.matches(":popover-open")) el.showPopover();
        visible.value = true;
    };

    /** Show the tip — after `showDelay` for hover, or at once when `immediate` (focus). */
    const show = (immediate = false): void => {
        clearTimer();
        if (immediate) reveal();
        else timer = setTimeout(reveal, showDelay);
    };

    /** Hide the tip and cancel a queued reveal. Safe to call when already hidden. */
    const hide = (): void => {
        clearTimer();
        const el = tooltipRef.value;
        if (el && el.matches(":popover-open")) el.hidePopover();
        visible.value = false;
    };

    // A trigger can unmount mid-hover (route change, list re-render) — don't let
    // a queued reveal fire into a torn-down component.
    onBeforeUnmount(clearTimer);

    return { tooltipRef, tooltipId, anchorName, visible, show, hide };
};
