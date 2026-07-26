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
 * Shows on hover after a hover-intent delay and immediately on keyboard focus;
 * hides on leave / blur / Escape / unmount. A falsy or empty `text` makes the
 * element inert, so a hint can be toggled off reactively without `v-if`.
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
          /** ms of hover-intent before showing. */
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
    /** Detach every listener registered for this element. */
    teardown: () => void;
};
const states = new WeakMap<HTMLElement, TriggerState>();

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
        const { showFor, hideFor } = useTooltipLayer();
        const anchor = nextAnchorName();
        // Inline, because the name has to be unique per element — the layer's
        // `position-anchor` is pointed at it while this trigger owns the tip.
        el.style.setProperty("anchor-name", anchor);

        const state: TriggerState = { request: resolveRequest(binding, anchor), teardown: () => {} };
        states.set(el, state);

        const onEnter = (): void => showFor(el, state.request);
        const onFocus = (): void => showFor(el, state.request, true);
        const onRelease = (): void => hideFor(el);
        const onKeydown = (event: KeyboardEvent): void => {
            if (event.key === "Escape") hideFor(el);
        };

        // focusin/focusout (not focus/blur) so a trigger that only *contains* the
        // focusable control — the Tooltip wrapper around a radio + label — still
        // reacts to keyboard users.
        el.addEventListener("mouseenter", onEnter);
        el.addEventListener("mouseleave", onRelease);
        el.addEventListener("focusin", onFocus);
        el.addEventListener("focusout", onRelease);
        el.addEventListener("keydown", onKeydown);

        state.teardown = (): void => {
            el.removeEventListener("mouseenter", onEnter);
            el.removeEventListener("mouseleave", onRelease);
            el.removeEventListener("focusin", onFocus);
            el.removeEventListener("focusout", onRelease);
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
