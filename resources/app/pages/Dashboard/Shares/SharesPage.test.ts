import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import SharesPage, { type ShareRow } from "./SharesPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The reader's own share links, at /dashboard/shared.
 *
 * WHAT IS TESTED HERE IS WHAT PHP CANNOT SEE, per the repo's layer rule. The props are pinned
 * by `assertInertia` in tests/Feature/Shares/RevokeShareTest.php and the revoke journey by
 * tests/e2e/app/share.spec.ts, so neither is repeated. What is this page's own:
 *
 *   - THE EXPIRY IN THE READER'S LOCALE. The server sends an ISO-8601 instant and knows
 *     neither their language nor their timezone, so which of "18.8.2026" and "Aug 18, 2026"
 *     they read is decided here.
 *   - AN EXPIRED ROW SAYING SO IN WORDS rather than printing a date that has quietly passed.
 *     The same date read two ways is exactly what a reader misreads, and the row is the one
 *     they are most likely to be revoking.
 *   - THE ACCESSIBLE NAME OF EACH REVOKE BUTTON carrying its subject. A column of identical
 *     "revoke" labels tells a screen-reader user which row they are on only by counting, and
 *     this is the one control in the app that breaks something already in somebody else's
 *     hands.
 */

/** A live album share; tests override only what they are about. */
const share = (overrides: Partial<ShareRow> = {}): ShareRow => ({
    id: "share-1",
    kind: "album",
    name: "OK Computer",
    url: "https://mixtape.test/s/share-1",
    validUntil: "2026-08-18T09:30:00+00:00",
    expired: false,
    ...overrides
});

/** Mount the page, optionally in English. */
const page = (shares: ShareRow[] = [share()], locale: "de" | "en" = "de") =>
    mountApp(SharesPage, { props: { shares }, locale });

describe("SharesPage", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "u1", name: "Ash", email: "a@b.c" } }, shares: true } });
    });

    it("says when a link stops working, in the reader's own locale", () => {
        // THE REASON THIS FILE EXISTS: the server sends a raw instant, and this is where it
        // becomes a date somebody can read.
        expect(page().text()).toContain("18.08.2026");
        expect(page([share()], "en").text()).toContain("Aug 18, 2026");
    });

    it("says an expired link has expired, rather than printing the date it died on", () => {
        const wrapper = page([share({ expired: true })]);

        expect(wrapper.text()).toContain(translate("dashboard.shares.expired"));
        expect(wrapper.text()).not.toContain("18.08.2026");
    });

    it("still offers to revoke an expired link, which is how a reader tidies up", () => {
        // Listed and revocable — a list that quietly dropped dead rows would read as links
        // going missing, and pruning does not exist yet.
        expect(page([share({ expired: true })]).find(".shares__revoke").exists()).toBe(true);
    });

    it("names the subject in each revoke button, not just the verb", () => {
        const button = page().find(".shares__revoke");

        expect(button.attributes("aria-label")).toBe(
            translate("dashboard.shares.revoke.label").replace("{name}", "OK Computer")
        );
    });

    it("labels each row with the kind of thing that was shared", () => {
        // "(Album)" in running text became a pip on 2026-08-12, so what is asserted is the
        // WORD rather than the punctuation around it.
        for (const kind of ["song", "album", "artist"] as const) {
            const wrapper = page([share({ kind })]);

            expect(wrapper.find(".shares__kind").text()).toBe(translate(`dashboard.shares.kind.${kind}`));
            wrapper.unmount();
        }
    });

    it("opens the confirmation before anything is revoked", async () => {
        // The dialog is TELEPORTED to <body>, so it is read off the document rather than off
        // the wrapper — the same way ShareModal's own spec reaches it.
        const dialog = () => document.querySelector(".modal-dialog");

        const wrapper = page();
        expect(dialog()).toBeNull();

        await wrapper.find(".shares__revoke").trigger("click");

        // It names the subject: a list of similar rows is precisely where revoking the
        // neighbour of the one you meant is easy.
        expect(dialog()).not.toBeNull();
        expect(dialog()!.textContent).toContain("OK Computer");
    });

    it("copies the link, and says which row it came from", async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal("navigator", { clipboard: { writeText } });

        const wrapper = page([share(), share({ id: "share-2", name: "Kid A", url: "https://mixtape.test/s/share-2" })]);
        await wrapper.findAll(".shares__copy")[1]!.trigger("click");
        await wrapper.vm.$nextTick();

        // The ABSOLUTE url the server sent — a root-relative path pasted into a chat window is
        // not a link at all.
        expect(writeText).toHaveBeenCalledWith("https://mixtape.test/s/share-2");
        // …and the tick lands on THAT row only: one composable, one flag, many buttons.
        expect(wrapper.findAll(".shares__copy").map(button => button.html().includes("check"))).toStrictEqual([
            false,
            true
        ]);

        vi.unstubAllGlobals();
    });

    it("offers no copy button on an expired link", () => {
        // Copying one means pasting a 404 into somebody's chat window believing you have sent
        // them music. Revoke stays, because tidying up is what the row is still good for.
        const wrapper = page([share({ expired: true })]);

        expect(wrapper.find(".shares__copy").exists()).toBe(false);
        expect(wrapper.find(".shares__revoke").exists()).toBe(true);
    });

    it("draws an empty state rather than a bare heading", () => {
        // Reachable in one case only — the reader revoked their last link and is still here.
        const wrapper = page([]);

        expect(wrapper.find(".shares").exists()).toBe(false);
        expect(wrapper.text()).toContain(translate("dashboard.shares.empty"));
    });
});
