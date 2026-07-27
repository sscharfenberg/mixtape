/******************************************************************************
 * v-tooltip
 * Gives any single element a floating text hint without wrapping it in extra
 * markup — the reason this exists next to Tooltip.vue: a `<th>`'s sort button
 * can't take a wrapper span (the head's CSS sizes the button to fill the cell,
 * and `th:not(:has(button))` switches the padding), and one popover node per
 * column would be wasteful. The directive instead makes the element itself the
 * CSS anchor and shows the app's single TooltipLayer against it.
 *
 * Registered globally in main.ts (`app.directive("tooltip", vTooltip)`); the
 * template types come from the GlobalDirectives augmentation in
 * resources/types/directives.d.ts.
 *
 *   v-tooltip="text"                        // hint, default placement "top"
 *   v-tooltip:bottom="text"                 // placement via the directive arg
 *   v-tooltip="{ text, placement, delay }"  // full form
 *
 * Triggers, by input device — a hint has to be reachable without a hover, or it
 * doesn't exist on a phone:
 *
 *   mouse     hover shows after the hover-intent delay, leaving hides; a click
 *             only *dismisses* (hover keeps owning the reveal, so clicking the
 *             same control again can't flicker the tip back on)
 *   touch/pen no hover exists, so a tap toggles the tip: it stays pinned until
 *             the trigger is tapped again, something else is tapped, or scrolling
 *             begins
 *   keyboard  focus shows immediately, blur/Escape hides; Enter/Space activation
 *             leaves the tip alone
 *
 * A falsy or empty `text` makes the element inert, so a hint can be toggled off
 * reactively without `v-if`.
 *****************************************************************************/
import type { Directive, DirectiveBinding } from "vue";
import type { TooltipPlacement, TooltipRequest } from "Composables/useTooltipLayer";
import { nextAnchorName, useTooltipLayer } from "Composables/useTooltipLayer";

/** What `v-tooltip` accepts — bare text or the option object; falsy text = inert. */
export type TooltipValue =
    | string
    | null
    | undefined
    | {
          /** The hint text, already translated. */
          text: string | null | undefined;
          /** Side of the trigger the tip sits on. Overrides the directive argument. */
          placement?: TooltipPlacement;
          /** ms of hover-intent before showing; focus and taps ignore it. */
          delay?: number;
      };

/** Hover-intent delay in ms when the binding doesn't set one. */
const DEFAULT_DELAY = 300;

/** Side the tip sits on when neither the object form nor the directive argument picks one. */
const DEFAULT_PLACEMENT: TooltipPlacement = "top";

// Per-element bookkeeping, keyed weakly so a removed trigger can't leak. The
// handlers read `state.request` at event time rather than closing over a value,
// so an updated binding is picked up without re-registering listeners.
type TriggerState = {
    /** The trigger's current, resolved settings. */
    request: TooltipRequest;
    /**
     * `pointerType` of the pointerdown a pending `click` will come from, or null
     * when the next click is keyboard-made. A click event carries no pointerType
     * of its own, and `MouseEvent.detail` can't stand in for it: a tap's click
     * reports 0 in some engines, exactly like an Enter activation does.
     */
    pointer: string | null;
    /**
     * `pointerMoves` at the moment a mouse click dismissed the tip, or null when
     * nothing is latched. This is what makes "a click dismisses" stick: a DOM update
     * under a *stationary* cursor — a DataTable sort re-rendering its header — makes
     * Chrome re-dispatch pointerleave/pointerenter on the trigger, which would pop
     * the tip back up `delay` ms after the click that dismissed it. Verified in
     * Chrome 150: click → mutation → leave → enter, all within ~20ms, no movement.
     */
    dismissedAtMove: number | null;
    /** Detach every listener registered for this element. */
    teardown: () => void;
};
const states = new WeakMap<HTMLElement, TriggerState>();

// A monotonic count of real pointer movement, shared by every trigger — the one
// signal that separates a genuine hover from the re-dispatch described above, since
// a re-dispatch is preceded by no pointermove at all while any real approach to the
// element is preceded by several. Coordinates can't do this job: a re-entry carries
// the unchanged cursor position, which is also what "left and came back to the same
// pixel" looks like. Installed once on the first trigger's mount, passive, never
// removed — the handler is a single increment.
let pointerMoves = 0;
let tracking = false;

/** Start counting pointer movement document-wide; idempotent, so every mount may call it. */
const trackPointerMoves = (): void => {
    if (tracking) return;
    tracking = true;
    document.addEventListener(
        "pointermove",
        () => {
            pointerMoves += 1;
        },
        { passive: true, capture: true }
    );
};

/**
 * Normalise a binding (string or object, plus the optional `v-tooltip:<side>`
 * argument) into a TooltipRequest. The object's `placement` wins over the
 * argument so a computed option object can always have the final say.
 */
const resolveRequest = (binding: DirectiveBinding<TooltipValue>, anchor: string): TooltipRequest => {
    const value = binding.value;
    const options = typeof value === "object" && value !== null ? value : { text: value };
    return {
        text: options.text ?? "",
        placement:
            ("placement" in options ? options.placement : undefined) ??
            (binding.arg as TooltipPlacement) ??
            DEFAULT_PLACEMENT,
        delay: ("delay" in options ? options.delay : undefined) ?? DEFAULT_DELAY,
        anchor
    };
};

export const vTooltip: Directive<HTMLElement, TooltipValue> = {
    /** Make the element a CSS anchor and wire the pointer/keyboard triggers. */
    mounted(el, binding) {
        const { showFor, hideFor, toggleFor } = useTooltipLayer();
        trackPointerMoves();
        const anchor = nextAnchorName();
        // Inline, because the name has to be unique per element — the layer's
        // `position-anchor` is pointed at it while this trigger owns the tip.
        el.style.setProperty("anchor-name", anchor);

        const state: TriggerState = {
            request: resolveRequest(binding, anchor),
            pointer: null,
            dismissedAtMove: null,
            teardown: () => {}
        };
        states.set(el, state);

        // Pointer events, not mouse events, so the hover path can be restricted to
        // an actual mouse: on touch the browser emulates a hover around every tap
        // (with the leave arriving at touch-end, right after the tap), which would
        // show the tip for a blink and then kill it. Touch and pen skip hover
        // entirely and go through onClick below.
        const onEnter = (event: PointerEvent): void => {
            if (event.pointerType !== "mouse") return;
            // A dismissal holds until the pointer really moves — see dismissedAtMove.
            if (state.dismissedAtMove !== null) {
                if (state.dismissedAtMove === pointerMoves) return;
                state.dismissedAtMove = null;
            }
            showFor(el, state.request);
        };
        const onLeave = (event: PointerEvent): void => {
            if (event.pointerType !== "mouse") return;
            hideFor(el);
        };

        /* Keyboard focus reveals instantly — but only *keyboard* focus. A click
           focuses the trigger too, and revealing there would flash the tip for the
           few ms until the click dismisses it again. `:focus-visible` is exactly
           that distinction, decided by the browser rather than guessed at here. */
        const onFocus = (event: FocusEvent): void => {
            const target = event.target;
            if (target instanceof Element && !target.matches(":focus-visible")) return;
            showFor(el, state.request, true);
        };
        const onFocusOut = (): void => hideFor(el);

        /** Remember which device the click about to arrive came from; read by onClick. */
        const onPointerDown = (event: PointerEvent): void => {
            state.pointer = event.pointerType;
        };

        /* The click path, split by device. With a mouse, hover owns showing, so a
           click only dismisses, latched by `dismissedAtMove` until the pointer really
           moves — that's what lets you hammer a control (sorting a DataTable column,
           flipping a widget mode) without the tip flickering back on between clicks.
           Touch/pen have no hover at all, so there the tap *is* the affordance and it
           toggles. */
        const onClick = (): void => {
            const pointer = state.pointer;
            state.pointer = null; // consumed: the next click needs its own pointerdown
            if (pointer === null) return; // keyboard activation, or a scripted el.click()
            if (pointer === "mouse") {
                state.dismissedAtMove = pointerMoves;
                hideFor(el);
            } else {
                state.dismissedAtMove = null; // a tap is a fresh start, whatever a mouse did before
                toggleFor(el, state.request);
            }
        };

        const onKeydown = (event: KeyboardEvent): void => {
            // Any key clears the pointer memory, so an Enter/Space activation can't
            // be mistaken for the click of an earlier pointerdown that never became
            // one (pressed on the trigger, then dragged off and released).
            state.pointer = null;
            if (event.key === "Escape") hideFor(el);
        };

        // focusin/focusout (not focus/blur) so a trigger that only *contains* the
        // focusable control — the Tooltip wrapper around a radio + label — still
        // reacts to keyboard users.
        el.addEventListener("pointerenter", onEnter);
        el.addEventListener("pointerleave", onLeave);
        el.addEventListener("pointerdown", onPointerDown);
        el.addEventListener("click", onClick);
        el.addEventListener("focusin", onFocus);
        el.addEventListener("focusout", onFocusOut);
        el.addEventListener("keydown", onKeydown);

        state.teardown = (): void => {
            el.removeEventListener("pointerenter", onEnter);
            el.removeEventListener("pointerleave", onLeave);
            el.removeEventListener("pointerdown", onPointerDown);
            el.removeEventListener("click", onClick);
            el.removeEventListener("focusin", onFocus);
            el.removeEventListener("focusout", onFocusOut);
            el.removeEventListener("keydown", onKeydown);
        };
    },

    /** Adopt a changed binding, and refresh the tip if this trigger has it open. */
    updated(el, binding) {
        const state = states.get(el);
        if (!state) return;
        state.request = resolveRequest(binding, state.request.anchor);
        useTooltipLayer().updateFor(el, state.request);
    },

    /**
     * A trigger can disappear mid-hover (Inertia visit, list re-render) — detach
     * its listeners and make sure it leaves neither an open tip nor a queued
     * reveal pointing at a detached anchor.
     */
    beforeUnmount(el) {
        states.get(el)?.teardown();
        states.delete(el);
        useTooltipLayer().hideFor(el);
    }
};
