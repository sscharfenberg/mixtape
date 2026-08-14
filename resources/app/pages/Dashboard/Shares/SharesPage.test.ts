import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { ShareRow } from "Types/shares";
import SharesPage from "./SharesPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The reader's own share links, at /dashboard/shared — live ones on top, expired ones under
 * their own heading.
 *
 * WHAT IS TESTED HERE IS WHAT PHP CANNOT SEE, per the repo's layer rule. WHICH half a link is in
 * is the server's decision and is pinned by `assertInertia` in
 * tests/Feature/Shares/RevokeShareTest.php, and the revoke journey by tests/e2e/app/share.spec.ts,
 * so neither is repeated. What is this page's own:
 *
 *   - THE EXPIRY IN THE READER'S LOCALE. The server sends an ISO-8601 instant and knows
 *     neither their language nor their timezone, so which of "18.8.2026" and "Aug 18, 2026"
 *     they read is decided here.
 *   - AN EXPIRED ROW SAYING SO IN WORDS rather than printing a date that has quietly passed.
 *     The same date read two ways is exactly what a reader misreads, and the row is the one
 *     they are most likely to be revoking.
 *   - THE SECOND HEADING BEING CONDITIONAL. Most readers have no dead links, and a heading
 *     standing over an empty list would tell them about a state they have never been in.
 *   - THE ACCESSIBLE NAME OF EACH REVOKE BUTTON carrying its subject. A column of identical
 *     "revoke" labels tells a screen-reader user which row they are on only by counting, and
 *     this is the one control in the app that breaks something already in somebody else's
 *     hands.
 */

/** A share link; tests override only what they are about. */
const share = (overrides: Partial<ShareRow> = {}): ShareRow => ({
    id: "share-1",
    kind: "album",
    name: "OK Computer",
    url: "https://mixtape.test/s/share-1",
    validUntil: "2026-08-18T09:30:00+00:00",
    ...overrides
});

/** Mount the page with a live half, an expired half, and optionally in English. */
const page = (shares: ShareRow[] = [share()], expiredShares: ShareRow[] = [], locale: "de" | "en" = "de") =>
    mountApp(SharesPage, { props: { shares, expiredShares }, locale });

describe("SharesPage", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "u1", name: "Ash", email: "a@b.c" } }, shares: true } });
    });

    it("says when a link stops working, in the reader's own locale", () => {
        // THE REASON THIS FILE EXISTS: the server sends a raw instant, and this is where it
        // becomes a date somebody can read.
        expect(page().text()).toContain("18.08.2026");
        expect(page([share()], [], "en").text()).toContain("Aug 18, 2026");
    });

    it("says an expired link has expired, rather than printing the date it died on", () => {
        const wrapper = page([], [share()]);

        expect(wrapper.text()).toContain(translate("dashboard.shares.expired"));
        expect(wrapper.text()).not.toContain("18.08.2026");
    });

    it("draws the expired heading only when there is something under it", () => {
        // A run of headings down a page alternates its glowing-border tab, and this one hugs the
        // right edge — which is also what says the dead half is the lesser one.
        const headline = translate("dashboard.shares.expiredHeadline");

        expect(page().text()).not.toContain(headline);

        const wrapper = page([share()], [share({ id: "share-2", name: "Kid A" })]);
        const heading = wrapper.findAll("h2").find(element => element.text().includes(headline));

        expect(heading).toBeDefined();
        expect(heading!.classes()).toContain("glowing-border--right");
        // One row in each half, and the dead one is in the dead list.
        expect(wrapper.findAll(".shares--active .shares__row")).toHaveLength(1);
        expect(wrapper.find(".shares--expired .shares__row").text()).toContain("Kid A");
    });

    it("still offers to revoke an expired link, which is how a reader tidies up", () => {
        // Listed and revocable — a page that dropped dead rows would read as links going
        // missing, and the sweep does not take one for thirty days.
        expect(page([], [share()]).find(".shares__revoke").exists()).toBe(true);
    });

    it("makes the word 'expired' the way back, and only on a dead row", () => {
        // Deliberate: the remedy hangs off the word a reader is already
        // looking at, rather than a fourth control on the row. It is the same pip in the same
        // place — a <button> instead of a <span> — so what this asserts is the ELEMENT, and that
        // a live row's validity stays a plain fact.
        const dead = page([], [share()]);
        const renew = dead.find(".shares__renew");

        expect(renew.exists()).toBe(true);
        expect(renew.element.tagName).toBe("BUTTON");
        expect(renew.text()).toContain(translate("dashboard.shares.expired"));
        // Named for a screen reader, like the revoke button beside it: a column of identical
        // "expired" labels says which row you are on only by counting.
        expect(renew.attributes("aria-label")).toBe(
            translate("dashboard.shares.renew.label").replace("{name}", "OK Computer")
        );

        expect(page().find(".shares__renew").exists()).toBe(false);
    });

    it("asks before it puts a link back in somebody's hands", async () => {
        // Reviving a dead link is not a stray-click sort of act — the URL is already in a chat
        // window somewhere — so the pip opens the page's dialog rather than sending the PATCH.
        const dialog = () => document.querySelector(".modal-dialog");

        const wrapper = page([], [share()]);
        await wrapper.find(".shares__renew").trigger("click");

        expect(dialog()).not.toBeNull();
        expect(dialog()!.textContent).toContain(translate("dashboard.shares.renew.header"));
        // It names the subject and says what happens: the same link, seven days from now.
        expect(dialog()!.textContent).toContain("OK Computer");
    });

    it("names the subject in each revoke button, not just the verb", () => {
        const button = page().find(".shares__revoke");

        expect(button.attributes("aria-label")).toBe(
            translate("dashboard.shares.revoke.label").replace("{name}", "OK Computer")
        );
    });

    it("labels each row with the kind of thing that was shared", () => {
        // The kind is a pip rather than "(Album)" in running text, so what is asserted is the
        // WORD rather than the punctuation around it. `playlist` is on the list
        // and is included here because a row's pip is the only thing telling a reader which of
        // two similarly-named subjects they are about to revoke.
        for (const kind of ["song", "album", "artist", "playlist"] as const) {
            const wrapper = page([share({ kind })]);

            expect(wrapper.find(".shares__kind").text()).toBe(translate(`dashboard.shares.kind.${kind}`));
            wrapper.unmount();
        }
    });

    it("opens the confirmation before anything is revoked", async () => {
        // The dialog is TELEPORTED to <body>, so it is read off the document rather than off
        // the wrapper — the same way ShareModal's own spec reaches it. It is the PAGE's, so a
        // row in either half opens the one dialog.
        const dialog = () => document.querySelector(".modal-dialog");

        const wrapper = page([], [share()]);
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
        // …and the tick lands on THAT row only, which is what a clipboard composable per ROW
        // buys: one flag per row, rather than one flag and a record of which id used it last.
        expect(wrapper.findAll(".shares__copy").map(button => button.html().includes("check"))).toStrictEqual([
            false,
            true
        ]);

        vi.unstubAllGlobals();
    });

    it("offers no copy button on an expired link", () => {
        // Copying one means pasting a 404 into somebody's chat window believing you have sent
        // them music. Revoke stays, because tidying up is what the row is still good for.
        const wrapper = page([], [share()]);

        expect(wrapper.find(".shares__copy").exists()).toBe(false);
        expect(wrapper.find(".shares__revoke").exists()).toBe(true);
    });

    it("draws an empty state rather than a bare heading", () => {
        // Reachable two ways: the reader revoked their last live link, or every link they made
        // has run out — and then this line is the honest answer to "what am I sharing now",
        // with their links still on the page below.
        const wrapper = page([], [share()]);

        expect(wrapper.find(".shares--active").exists()).toBe(false);
        expect(wrapper.text()).toContain(translate("dashboard.shares.empty"));
        expect(wrapper.find(".shares--expired").exists()).toBe(true);
    });
});
