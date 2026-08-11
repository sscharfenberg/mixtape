import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import ShareModal from "./ShareModal.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The dialog a reader gets once a share link has been minted.
 *
 * WHAT ONLY THIS LAYER CAN SEE, and so what is tested here:
 *
 *   - THE EXPIRY IS FORMATTED, in the reader's locale, from the raw ISO-8601 instant the
 *     server sent. PHP cannot check that (it sent a machine string on purpose) and Playwright
 *     would be asserting a date it had to compute itself.
 *   - THE COPY GESTURE IS ON THE FIELD, not on a button beside it — the whole interaction the
 *     owner asked for — and the confirmation replaces the invitation when it succeeds.
 *   - THE FIELD IS READONLY. A reader who edits the URL and then copies it has a link to a
 *     row that does not exist, and would have no way of knowing.
 *
 * EVERYTHING IS QUERIED OFF `document`, not off the wrapper: Modal TELEPORTS to <body>, so
 * `wrapper.find()` reaches straight past it — the same reason PlaylistExportModal's tests
 * drive native events rather than VTU helpers.
 *
 * WHAT IT DELIBERATELY DOES NOT TEST: that the text really reaches the system clipboard.
 * happy-dom has no clipboard, so a test here can only prove `writeText` was called with the
 * right string — which is what it does. Whether a real click on a real field really copies is
 * `tests/e2e/app/share.spec.ts`, in a browser, with the permission granted.
 */

/** The link every test mounts with. */
const URL_ = "https://mixtape.test/s/abc-123";

/** Mount the modal with a link in hand — the only state it is ever in. */
const modal = (validUntil = "2026-08-18T12:00:00+00:00") => {
    setPage({ props: { auth: { user: null }, csrfToken: "token" } });

    return mountApp(ShareModal, { props: { url: URL_, validUntil } });
};

/** The teleported dialog's text. */
const text = (): string => document.querySelector(".modal-dialog")!.textContent ?? "";

/** The link field itself, in the teleported dialog. */
const field = (): HTMLInputElement => document.querySelector("input.share-modal__link")!;

/** Fire one of the two gestures that copy, and let the promise chain settle. */
const gesture = async (type: "click" | "focus"): Promise<void> => {
    field().dispatchEvent(new Event(type, { bubbles: true }));
    await new Promise(resolve => setTimeout(resolve, 0));
    await nextTick();
};

describe("ShareModal", () => {
    beforeEach(() => {
        resetInertia();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("shows the link, and only ever as text to copy", () => {
        modal();

        expect(field().value).toBe(URL_);
        // Editable, this would let a reader copy a link to a row that never existed.
        expect(field().hasAttribute("readonly")).toBe(true);
    });

    it("says when the link dies, in the reader's own locale", () => {
        // German is the default: day.month.year, not the ISO string the server sent.
        modal();

        expect(text()).toContain("18.08.2026");
        expect(text()).not.toContain("2026-08-18T12:00:00");
    });

    it("warns that whoever holds the link can listen", () => {
        // The honest note, and the reason this is a dialog rather than a line of text beside
        // the button: forwarding cannot be prevented, so a reader should read that once.
        modal();

        expect(text()).toContain("ohne Konto");
    });

    it("copies the link when the field is clicked, and says so", async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal("navigator", { clipboard: { writeText } });

        modal();
        expect(text()).toContain("Klick ins Feld");

        await gesture("click");

        expect(writeText).toHaveBeenCalledExactlyOnceWith(URL_);
        expect(text()).toContain("Kopiert.");
        expect(text()).not.toContain("Klick ins Feld");
    });

    it("copies on focus too, so a keyboard reaches it", async () => {
        // Tabbing into the field is how a keyboard "clicks" into it; without this the
        // interaction would be pointer-only, which is the trap a click-to-copy field sets.
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal("navigator", { clipboard: { writeText } });

        modal();
        await gesture("focus");

        expect(writeText).toHaveBeenCalledExactlyOnceWith(URL_);
    });

    it("keeps the invitation standing when the clipboard refuses", async () => {
        // A denied permission is common on mobile, and `useClipboard` swallows it. What must
        // NOT happen is the modal claiming a copy that did not occur.
        vi.stubGlobal("navigator", { clipboard: { writeText: vi.fn().mockRejectedValue(new Error("denied")) } });

        modal();
        await gesture("click");

        expect(text()).not.toContain("Kopiert.");
        expect(text()).toContain("Klick ins Feld");
    });
});
