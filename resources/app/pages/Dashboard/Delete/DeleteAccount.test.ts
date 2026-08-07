import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import DeleteAccount from "./DeleteAccount.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The irreversible one. The section itself is a warning and a button; the whole safety
 * mechanism is that the button opens a MODAL asking for the password rather than deleting
 * anything. So what is worth pinning is the gap between the two:
 *
 *   - pressing the section's button must NOT delete. It submits a form, and a form that
 *     posted straight to the endpoint would look almost identical in a diff — same button,
 *     same handler shape — while removing the confirmation entirely.
 *   - the modal must not exist before then. A `v-show`-style always-mounted modal would
 *     mount AccountDeleteModal on every dashboard load, which runs its autofocus and puts a
 *     password field in the DOM of a page nobody asked to delete anything from.
 *   - the whole flow has to be REVERSIBLE up to the last press: closing the modal returns
 *     the section to its resting state, with nothing sent.
 *
 * `fetch` is stubbed and asserted-against rather than mocked away, because "did anything
 * reach /user/delete" is exactly the question. The modal's own error handling belongs to
 * useDeleteAccount.test.ts; what happens in a real browser — the native dialog, its focus
 * trap, Escape — belongs to Playwright.
 */

/** Every request made since the stub was installed. */
let fetchMock: ReturnType<typeof vi.fn>;

/** Mount the section. */
const section = () => {
    setPage({ props: { csrfToken: "token" } });

    return mountApp(DeleteAccount, { attachTo: document.body });
};

/** The teleported modal, if one is open. */
const modalInDocument = (): Element | null => document.querySelector("dialog.modal-dialog");

describe("DeleteAccount", () => {
    beforeEach(() => {
        resetInertia();
        fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ redirect: "/" }) });
        vi.stubGlobal("fetch", fetchMock);
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("shows the warning and its button without any modal in the page", () => {
        const wrapper = section();

        expect(wrapper.text()).toContain(translate("dashboard.delete.warning"));
        expect(wrapper.text()).toContain(translate("dashboard.delete.reversed"));
        expect(modalInDocument()).toBeNull();
    });

    it("only ASKS when the button is pressed — nothing is sent", async () => {
        const wrapper = section();

        await wrapper.find("form").trigger("submit");

        expect(modalInDocument()).not.toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("takes the modal away again when it is dismissed, with nothing sent", async () => {
        const wrapper = section();
        await wrapper.find("form").trigger("submit");

        wrapper.findComponent({ name: "AccountDeleteModal" }).vm.$emit("close");
        await nextTick();

        expect(modalInDocument()).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("anchors the section so the dashboard's jump-nav can reach it", () => {
        expect(section().find("#deleteSection").exists()).toBe(true);
    });
});

describe("AccountDeleteModal", () => {
    /*
     * Modal teleports to <body>, so the modal's markup is NOT inside the section's wrapper —
     * `wrapper.find()` reaches past it into the section and quietly matches the wrong things
     * (the section has a submit button of its own). Everything below reads the document.
     */

    /** Open the modal through the section, which is the only path to it. */
    const openModal = async (): Promise<void> => {
        await section().find("form").trigger("submit");
    };

    /** A node inside the open modal. */
    const inModal = <T extends Element>(selector: string): T =>
        document.querySelector<T>(`dialog.modal-dialog ${selector}`)!;

    /** The password field. */
    const passwordField = (): HTMLInputElement => inModal<HTMLInputElement>("#delete-password");

    /** Type into the password field the way a reader would, so `v-model` sees it. */
    const typePassword = async (value: string): Promise<void> => {
        const field = passwordField();
        field.value = value;
        field.dispatchEvent(new window.Event("input", { bubbles: true }));
        await nextTick();
    };

    beforeEach(() => {
        resetInertia();
        fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ redirect: "/" }) });
        vi.stubGlobal("fetch", fetchMock);
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("refuses to submit an empty password, so the confirmation cannot be pressed past", async () => {
        await openModal();

        expect(inModal("button[form='account-delete-form']").hasAttribute("disabled")).toBe(true);

        await typePassword("geheim");

        expect(inModal("button[form='account-delete-form']").hasAttribute("disabled")).toBe(false);
    });

    it("sends the typed password to the delete endpoint, and nothing before that", async () => {
        await openModal();
        await typePassword("geheim");

        expect(fetchMock).not.toHaveBeenCalled();

        inModal<HTMLFormElement>("#account-delete-form").dispatchEvent(
            new window.Event("submit", { cancelable: true, bubbles: true })
        );
        await nextTick();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/user/delete");
        expect(init.method).toBe("DELETE");
        expect(JSON.parse(init.body as string)).toStrictEqual({ password: "geheim" });
    });

    it("reveals the password as text on request, and says which state the toggle is in", async () => {
        await openModal();
        const toggle = inModal<HTMLButtonElement>(".form-row button[type='button']");

        expect(passwordField().getAttribute("type")).toBe("password");
        expect(toggle.getAttribute("aria-label")).toBe(translate("common.showPassword"));

        toggle.click();
        await nextTick();

        expect(passwordField().getAttribute("type")).toBe("text");
        expect(inModal(".form-row button[type='button']").getAttribute("aria-label")).toBe(
            translate("common.hidePassword")
        );
    });

    it("puts its confirm button in the footer but keeps it bound to the form", async () => {
        // The button is outside the <form> in the DOM (Modal's footer slot is a sibling of
        // the body), so `form="account-delete-form"` is the only thing making it submit.
        await openModal();
        const confirm = inModal(".modal-dialog__footer button[type='submit']");

        expect(confirm.getAttribute("form")).toBe("account-delete-form");
        expect(confirm.textContent).toContain(translate("dashboard.delete.modal.confirm"));
    });
});
