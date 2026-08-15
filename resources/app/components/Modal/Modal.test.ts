import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { mountApp, translate } from "Testing/mount";
import Modal from "./Modal.vue";

/*
 * Modal wraps a native <dialog>, and every one of its decisions is a place the native
 * behaviour and ours have to be reconciled. Four are worth holding still:
 *
 *   - it OPENS ITSELF on mount. Consumers render `<modal v-if="show">` and never call a
 *     method, so a missing `showModal()` would leave an inert dialog in the DOM: present
 *     to `find()`, invisible on screen, and outside the top layer. `open` is the only
 *     honest assertion of that.
 *   - Escape is INTERCEPTED. The native `cancel` closes the dialog immediately, which
 *     would tear the node out mid-animation; the handler preventDefaults and routes
 *     through the same exit path as every other dismissal. If that ever regressed the
 *     modal would still close — just abruptly — so only the animation-gated close proves it.
 *   - the exit animation is REDUCED-MOTION AWARE, and the two branches differ in *when*
 *     `close` is emitted, not merely in how it looks. Under reduced motion it fires at
 *     once; otherwise it waits for `animationend`. A consumer unmounting on `close`
 *     depends on that, and happy-dom never fires animation events on its own — so a
 *     handler waiting for one that never comes is exactly the hang this pins down.
 *   - a click on the BACKDROP closes, a click on the content does not. Both land on the
 *     same `@click`, and telling them apart is one `event.target === event.currentTarget`
 *     line — the sort that survives a refactor by looking correct.
 *
 * The component teleports to <body>, so assertions read the document, not the wrapper.
 * Focus trapping, the top layer and the real animation are the browser's job and are left
 * to Playwright (docs/testing.md → Choosing a layer).
 */

/** The teleported dialog element, which is where every assertion here looks. */
const dialog = (): HTMLDialogElement => document.querySelector<HTMLDialogElement>("dialog.modal-dialog")!;

/** The animated content box — the node whose `animationend` releases the close. */
const content = (): HTMLElement => document.querySelector<HTMLElement>(".modal-dialog__content")!;

/**
 * Pin `prefers-reduced-motion` for one test.
 *
 * happy-dom answers `(prefers-reduced-motion: no-preference)` with `true`, so the ANIMATED
 * branch is the default here — the opposite of what a naive reading assumes, and the reason
 * the reduced-motion case has to be stubbed rather than the other way round.
 */
const stubMotion = (allowed: boolean): void => {
    vi.stubGlobal(
        "matchMedia",
        (query: string) => ({ matches: allowed, media: query, addEventListener: () => {}, removeEventListener: () => {} })
    );
};

/** Mount a modal with a body, and a footer only when asked for. */
const modal = (withFooter = false) =>
    mountApp(Modal, {
        slots: { header: "Wirklich löschen?", default: "<p>Der Vorgang ist endgültig.</p>", ...(withFooter ? { footer: "<button>OK</button>" } : {}) },
        attachTo: document.body
    });

/** Fire the `animationend` the exit path waits for, as the browser would at the end of the fade. */
const finishExitAnimation = async (): Promise<void> => {
    content().dispatchEvent(new window.AnimationEvent("animationend", { bubbles: false }));
    await nextTick();
};

describe("Modal", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("shows itself as soon as it is rendered, rather than waiting to be told", () => {
        modal();

        expect(dialog().open).toBe(true);
    });

    it("renders the header, the body and — only when given one — a footer", () => {
        modal();

        expect(document.querySelector(".modal-dialog__footer")).toBeNull();
        expect(dialog().textContent).toContain("Wirklich löschen?");
        expect(dialog().textContent).toContain("Der Vorgang ist endgültig.");

        document.body.innerHTML = "";
        modal(true);

        expect(document.querySelector(".modal-dialog__footer")).not.toBeNull();
    });

    it("tells the body to round its own bottom edge when nothing follows it", () => {
        modal();
        expect(document.querySelector(".modal-dialog__body")!.className).toContain("modal-dialog__body--no-footer");

        document.body.innerHTML = "";
        modal(true);
        expect(document.querySelector(".modal-dialog__body")!.className).not.toContain("modal-dialog__body--no-footer");
    });

    it("closes at once for a reader who asked for less motion", async () => {
        stubMotion(false);
        const wrapper = modal();

        await wrapper.findComponent({ name: "ModalHeader" }).find("button").trigger("click");

        expect(dialog().open).toBe(false);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("waits for the exit animation before closing, so the modal is never yanked mid-fade", async () => {
        const wrapper = modal();

        await wrapper.findComponent({ name: "ModalHeader" }).find("button").trigger("click");

        // Still open, still emitting nothing: the close is parked on `animationend`.
        expect(dialog().open).toBe(true);
        expect(wrapper.emitted("close")).toBeUndefined();

        await finishExitAnimation();

        expect(dialog().open).toBe(false);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("takes Escape away from the browser so it runs the same exit as every other dismissal", async () => {
        const wrapper = modal();
        const cancel = new window.Event("cancel", { cancelable: true });

        dialog().dispatchEvent(cancel);
        await nextTick();

        // The native cancel would have closed it here and now; ours defers to the animation.
        expect(cancel.defaultPrevented).toBe(true);
        expect(dialog().open).toBe(true);

        await finishExitAnimation();

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("closes on the backdrop but not on the content, which share one handler", async () => {
        stubMotion(false);
        const wrapper = modal();

        await content().dispatchEvent(new window.MouseEvent("click", { bubbles: true }));
        await nextTick();

        expect(dialog().open).toBe(true);
        expect(wrapper.emitted("close")).toBeUndefined();

        dialog().dispatchEvent(new window.MouseEvent("click", { bubbles: true }));
        await nextTick();

        expect(dialog().open).toBe(false);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("ignores a second dismissal while the first is still animating out", async () => {
        // Two Escapes in quick succession, or an Escape then a backdrop click: without the
        // `isClosing` guard the second registers its own `animationend` listener and the
        // consumer is told to unmount twice.
        const wrapper = modal();

        await wrapper.findComponent({ name: "ModalHeader" }).find("button").trigger("click");
        dialog().dispatchEvent(new window.MouseEvent("click", { bubbles: true }));
        await finishExitAnimation();

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("names the close button for a screen reader, since its glyph carries no words", () => {
        modal();

        expect(document.querySelector(".btn-close")!.getAttribute("aria-label")).toBe(translate("common.close"));
    });
});
