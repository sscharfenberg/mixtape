import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useToast } from "Composables/useToast";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ToastContainer from "./ToastContainer.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * ToastContainer does two jobs, and the second is the subtle one.
 *
 * It renders useToast()'s active list — fine. It ALSO bridges Laravel's session flash
 * into that list, and it does so by watching `flash.nonce` rather than the flash object,
 * because Inertia's prop merge can hand back the same object reference twice. Watching
 * the object would silently swallow the second of two identical messages in a row —
 * exactly the "Gespeichert!" / "Gespeichert!" case a user would report as "the toast
 * didn't show that time". That is what the nonce tests below pin down.
 *
 * The component teleports to <body>, so assertions read the document, not the wrapper.
 */

/** Everything currently rendered in the teleported stack. */
const toastsInDocument = (): Element[] => [...document.querySelectorAll(".toast-container__item")];

/** Drain the toast singleton between tests. */
const drain = (): void => {
    const { activeToasts, removeToast } = useToast();
    while (activeToasts.value.length > 0) activeToasts.value.forEach(toast => removeToast(toast.id));
};

describe("ToastContainer", () => {
    beforeEach(() => {
        resetInertia();
        drain();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        drain();
        document.body.innerHTML = "";
    });

    it("renders the active toasts", async () => {
        mountApp(ToastContainer);
        useToast().addToast("Gespeichert!", "success");
        await nextTick();

        expect(toastsInDocument()).toHaveLength(1);
        expect(document.querySelector(".toast-container__item")!.textContent).toContain("Gespeichert!");
    });

    it.each([
        ["success", "check"],
        ["warning", "warning"],
        ["error", "error"],
        ["info", "info"]
    ])("gives a %s toast its own modifier class and icon", async (type, icon) => {
        mountApp(ToastContainer);
        useToast().addToast("Meldung", type as "success");
        await nextTick();

        const toast = document.querySelector(".toast-container__item")!;
        expect(toast.classList.contains(`toast-container__item--${type}`)).toBe(true);
        expect(toast.querySelector("use")!.getAttribute("xlink:href")).toBe(`#${icon}`);
    });

    it("dismisses a toast from its close button", async () => {
        mountApp(ToastContainer);
        useToast().addToast("Meldung");
        await nextTick();

        (document.querySelector(".toast-container__close") as HTMLButtonElement).click();
        await nextTick();

        expect(toastsInDocument()).toHaveLength(0);
    });

    it("exposes the auto-dismiss delay to CSS, and omits it for a sticky toast", async () => {
        mountApp(ToastContainer);
        useToast().addToast("Läuft ab", "info", 3000);
        useToast().addToast("Bleibt", "warning", 0);
        await nextTick();

        const [expiring, sticky] = toastsInDocument() as HTMLElement[];
        expect(expiring.style.getPropertyValue("--toast-duration")).toBe("3000ms");
        expect(sticky.style.getPropertyValue("--toast-duration")).toBe("");
        // No progress bar on a toast that never expires — it would never move.
        expect(sticky.querySelector(".toast-container__progress")).toBeNull();
        expect(expiring.querySelector(".toast-container__progress")).not.toBeNull();
    });

    it("announces the region politely for assistive tech", () => {
        mountApp(ToastContainer);

        const region = document.querySelector(".toast-container")!;
        expect(region.getAttribute("role")).toBe("region");
        expect(region.getAttribute("aria-live")).toBe("polite");
        expect(region.getAttribute("aria-label")).toBe(translate("common.notifications"));
    });

    describe("the Inertia flash bridge", () => {
        it("shows a flash that is already present on the initial page load", async () => {
            setPage({ props: { flash: { message: "Willkommen zurück!", type: "success", nonce: 1 } } });

            mountApp(ToastContainer);
            await nextTick();

            expect(document.querySelector(".toast-container__item")!.textContent).toContain("Willkommen zurück!");
        });

        it("carries the flash's type and duration through", async () => {
            // Login / logout flash a fast 3000ms toast this way.
            setPage({ props: { flash: { message: "Abgemeldet", type: "success", duration: 3000, nonce: 1 } } });

            mountApp(ToastContainer);
            await nextTick();

            const toast = document.querySelector(".toast-container__item") as HTMLElement;
            expect(toast.classList.contains("toast-container__item--success")).toBe(true);
            expect(toast.style.getPropertyValue("--toast-duration")).toBe("3000ms");
        });

        it("shows a flash that arrives on a later response", async () => {
            setPage({ props: { flash: { message: null, nonce: null } } });
            mountApp(ToastContainer);
            await nextTick();
            expect(toastsInDocument()).toHaveLength(0);

            setPage({ props: { flash: { message: "Gespeichert!", type: "success", nonce: 2 } } });
            await nextTick();

            expect(toastsInDocument()).toHaveLength(1);
        });

        it("shows two identical messages in a row, because it watches the nonce", async () => {
            // The whole reason the watcher keys on nonce rather than the flash object.
            setPage({ props: { flash: { message: "Gespeichert!", type: "success", nonce: 1 } } });
            mountApp(ToastContainer);
            await nextTick();

            setPage({ props: { flash: { message: "Gespeichert!", type: "success", nonce: 2 } } });
            await nextTick();

            expect(toastsInDocument()).toHaveLength(2);
        });

        it("ignores a response that carries no flash at all", async () => {
            setPage({ props: {} });

            mountApp(ToastContainer);
            await nextTick();

            expect(toastsInDocument()).toHaveLength(0);
        });

        it("ignores a flash whose nonce repeats, so a prop re-merge shows nothing twice", async () => {
            setPage({ props: { flash: { message: "Gespeichert!", type: "success", nonce: 7 } } });
            mountApp(ToastContainer);
            await nextTick();

            // Same nonce again — Inertia re-merged the same response.
            setPage({ props: { flash: { message: "Gespeichert!", type: "success", nonce: 7 } } });
            await nextTick();

            expect(toastsInDocument()).toHaveLength(1);
        });

        it("ignores a nonce that arrives without a message", async () => {
            setPage({ props: { flash: { message: null, type: null, nonce: 3 } } });

            mountApp(ToastContainer);
            await nextTick();

            expect(toastsInDocument()).toHaveLength(0);
        });
    });
});
