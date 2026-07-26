import type { Ref } from "vue";
import { nextTick, ref } from "vue";

/** Which side of the trigger the tip sits on — maps 1:1 to a CSS `position-area`. */
export type TooltipPlacement = "top" | "bottom" | "left" | "right";

/** A trigger's fully-resolved tooltip settings, handed over when it asks for the tip. */
export type TooltipRequest = {
    /** The hint text, already translated. An empty string makes the trigger inert. */
    text: string;
    /** Side of the trigger the tip sits on. */
    placement: TooltipPlacement;
    /** ms of hover-intent before a pointer-triggered tip appears; focus is immediate. */
    delay: number;
    /** The trigger's CSS `anchor-name` (a dashed-ident), which the tip anchors to. */
    anchor: string;
};

/** Everything the layer component and the `v-tooltip` directive share. */
export type UseTooltipLayerReturn = {
    /** Template ref for the single popover element holding the tip text. */
    tipRef: Ref<HTMLElement | null>;
    /** Text of the currently requested tip. */
    text: Ref<string>;
    /** `anchor-name` of the trigger that owns the tip, for the layer's `position-anchor`. */
    anchorName: Ref<string>;
    /** Side the tip sits on, for the layer's `position-area`. */
    placement: Ref<TooltipPlacement>;
    /** Reactive open state, for a caller that wants to mirror it. */
    visible: Ref<boolean>;
    /** Hand the tip to `trigger`; `immediate` skips the hover-intent delay (used on focus). */
    showFor: (trigger: HTMLElement, request: TooltipRequest, immediate?: boolean) => void;
    /** Release the tip from `trigger` — hides it, or just drops its queued reveal. */
    hideFor: (trigger: HTMLElement) => void;
    /** Refresh an already-open tip whose trigger's text/placement changed underneath it. */
    updateFor: (trigger: HTMLElement, request: TooltipRequest) => void;
};

/**
 * DOM id of the single tip element. Fixed rather than generated because exactly
 * one layer exists per document; the directive points the active trigger's
 * `aria-describedby` at it while the tip is open.
 */
export const TOOLTIP_ID = "app-tooltip";

// Module-level state — one tip node for the whole app, shared by the v-tooltip
// directive and the Tooltip wrapper component. (MixTape has no Pinia; a module
// singleton is the shared store, same as useToast.) One tip at a time is not a
// limitation but the intent: two hints on screen at once is never right.
const tipRef = ref<HTMLElement | null>(null);
const text = ref("");
const anchorName = ref("");
const placement = ref<TooltipPlacement>("top");
const visible = ref(false);

// The trigger currently owning the open tip, and the one whose reveal is still
// queued behind its hover-intent delay. Tracked separately so a leave/blur or an
// unmount only ever tears down *its own* tip — a stale mouseleave arriving after
// the pointer has reached the next trigger must not kill the newer tip.
let activeTrigger: HTMLElement | null = null;
let pendingTrigger: HTMLElement | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

// Monotonic counter behind nextAnchorName(); never resets, so a name can't be
// reused by a later trigger while an earlier one is still anchored.
let anchorCounter = 0;

/**
 * Mint a CSS `anchor-name` unique to one trigger. Called once per element by the
 * directive (not per show), because the name is written into the element's inline
 * style and must stay stable for as long as it lives.
 */
export const nextAnchorName = (): string => `--tt-${++anchorCounter}`;

/** Drop a queued reveal so a tip can't appear after the pointer has moved on. */
const clearTimer = (): void => {
    if (timer !== undefined) {
        clearTimeout(timer);
        timer = undefined;
    }
};

/**
 * Point the tip at `trigger` and promote it into the top layer.
 *
 * The `nextTick()` matters: text and the anchor/area custom properties are
 * reactive, so they must be flushed to the DOM *before* the popover shows —
 * otherwise the first painted frame still carries the previous trigger's anchor,
 * which reads as the tip jumping when moving between two adjacent triggers (two
 * table headers, say). `showPopover()` is guarded on `:popover-open` because it
 * throws when the popover is already open.
 */
const reveal = async (trigger: HTMLElement, request: TooltipRequest): Promise<void> => {
    pendingTrigger = null;
    activeTrigger = trigger;
    text.value = request.text;
    placement.value = request.placement;
    anchorName.value = request.anchor;

    await nextTick();
    if (activeTrigger !== trigger) return; // another trigger won the race mid-tick

    const el = tipRef.value;
    if (el && !el.matches(":popover-open")) el.showPopover();
    // The tip is a visual affordance, so the trigger keeps its own accessible
    // name; describedby is added only while shown so the description isn't
    // announced for a hint that isn't on screen.
    trigger.setAttribute("aria-describedby", TOOLTIP_ID);
    visible.value = true;
};

/** Queue (or immediately perform) a reveal for `trigger`; empty text is a no-op. */
const showFor = (trigger: HTMLElement, request: TooltipRequest, immediate = false): void => {
    if (!request.text) return;
    clearTimer();
    if (immediate) {
        pendingTrigger = null;
        void reveal(trigger, request);
    } else {
        pendingTrigger = trigger;
        timer = setTimeout(() => void reveal(trigger, request), request.delay);
    }
};

/** Hide the tip / cancel a queued reveal, but only for the trigger that owns it. */
const hideFor = (trigger: HTMLElement): void => {
    if (pendingTrigger === trigger) {
        clearTimer();
        pendingTrigger = null;
    }
    if (activeTrigger !== trigger) return;

    activeTrigger.removeAttribute("aria-describedby");
    activeTrigger = null;
    const el = tipRef.value;
    if (el && el.matches(":popover-open")) el.hidePopover();
    visible.value = false;
};

/**
 * Keep an open tip honest when its trigger's binding changes underneath it.
 * DataTable sorts with `preserveState`, so the hovered header button survives the
 * Inertia visit and only its hint flips direction — without this the tip would
 * keep claiming the old direction until the pointer left and came back.
 */
const updateFor = (trigger: HTMLElement, request: TooltipRequest): void => {
    if (activeTrigger !== trigger) return;
    if (!request.text) {
        hideFor(trigger);
        return;
    }
    text.value = request.text;
    placement.value = request.placement;
};

/**
 * Access the app's single tooltip layer. TooltipLayer.vue consumes the state
 * (and owns `tipRef`); the `v-tooltip` directive drives it through
 * showFor/hideFor/updateFor. Both get the same module singleton, so no wiring or
 * provide/inject is needed between them.
 */
export const useTooltipLayer = (): UseTooltipLayerReturn => ({
    tipRef,
    text,
    anchorName,
    placement,
    visible,
    showFor,
    hideFor,
    updateFor
});
