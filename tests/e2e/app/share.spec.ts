import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { pageHeading } from "../support/actions";

/*
 * Minting a share link from a detail page's hero, and copying it out of the modal.
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE, and the reason the other two layers are not enough:
 *
 *   - THE CLIPBOARD. happy-dom has none, so ShareModal's unit tests can only prove
 *     `writeText` was called with the right string. Whether a real click on a real field puts
 *     a real URL on a real clipboard is a question for an engine with a permission model, and
 *     this is the only place it can be asked.
 *   - THE ROUND TRIP. The mint is a plain `fetch` rather than an Inertia visit — precisely so
 *     the page is NOT re-rendered — and the CSRF token it has to carry by hand only matters
 *     against a real session. A missing one is a 419 that no unit test can see. The same
 *     mistake also has a visible second half: an Inertia visit here would navigate, or blank
 *     the page trying to read a page response out of JSON.
 *   - THAT NOTHING MOVES UNDERNEATH IT. The reader is still on the song when the modal opens.
 *
 * WHAT IS NOT HERE: following the link. That is the other half of the journey and it belongs to
 * a browser with NO session, so it lives in `tests/e2e/guest/share.spec.ts` — separated by
 * directory, so a stored login can never make it pass by accident. Everything the server decides
 * about minting — the seven days, the reuse of a live link, what may be shared at all — is in
 * tests/Feature/Shares/CreateShareTest.php, where it is cheap.
 *
 * ONE WORKER, because this file races ITSELF. Two of its tests reach the same album by the same
 * route — the first row of `/music/albums` on the default sort — on the same signed-in account:
 * one asserts that pressing share twice hands back the SAME link, the other revokes that album's
 * link and asserts it then 404s. Under `fullyParallel: true` a revoke landing between the first
 * press and the second makes the second mint a fresh id, and the reuse assertion fails on an app
 * behaving exactly as designed. `dashboard.spec.ts` also reads `/dashboard/shared` for this
 * account, which is why the file's rows are not private to it either.
 */

test.describe.configure({ mode: "default" });

/** Open the first row of a listing, which is how every detail page here is reached. */
const openFirstRow = async (page: Page, listing: string): Promise<void> => {
    await page.goto(listing);
    await page.locator("tbody tr").first().click();
    await expect(page.locator(".hero-section")).toBeVisible();
};

/** The link field inside the modal. */
const linkField = (page: Page) => page.locator(".share-modal__link");

/**
 * A seeded link (E2ESeeder → LIVE_SHARE), for the one test here that FOLLOWS one rather than
 * mints it. A literal, like the guest spec's: a spec cannot call PHP for a constant.
 */
const LIVE_SHARE = "019e0007-0000-7000-8000-000000000001";

/**
 * The seeded DEAD link this file re-activates (E2ESeeder → RENEWABLE_SHARE), a literal for the
 * same reason. Deliberately not the other expired one: that is the fixture `guest/share.spec.ts`
 * opens to see the "this link has expired" page.
 */
const RENEWABLE_SHARE = "019e0007-0000-7000-8000-000000000003";

test.describe("sharing", () => {
    test("mints a link from the song's hero and copies it out of the field", async ({ page, context }) => {
        // Granted on the CONTEXT, because a clipboard read is a permission and Chromium
        // refuses it silently otherwise — the test would then assert an empty string.
        await context.grantPermissions(["clipboard-read", "clipboard-write"]);

        await openFirstRow(page, "/music/songs");
        const title = await pageHeading(page).innerText();

        await page.locator(".share-button").click();

        // The modal opens on the ANSWER, not on the click: there is never a dialog standing
        // open with a spinner where a link should be.
        const modal = page.locator(".modal-dialog");
        await expect(modal).toBeVisible();
        await expect(linkField(page)).toHaveValue(/\/s\/[0-9a-f-]{36}$/u);

        // Still on the song, which is what says the mint was a fetch and not a visit.
        await expect(pageHeading(page)).toHaveText(title);

        const link = await linkField(page).inputValue();
        await linkField(page).click();

        await expect(modal).toContainText("Kopiert.");
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(link);
    });

    test("hands the same link back when the reader shares the same album again", async ({ page }) => {
        // Pressing twice must not mint twice — otherwise "My shares" fills with presses, and
        // a re-send would quietly reset the seven-day clock. Asserted here as well as in
        // PHPUnit because this is the path a reader actually takes: press, close, press again.
        await openFirstRow(page, "/music/albums");

        await page.locator(".share-button").click();
        await expect(page.locator(".modal-dialog")).toBeVisible();
        const first = await linkField(page).inputValue();

        await page.keyboard.press("Escape");
        await expect(page.locator(".modal-dialog")).toBeHidden();

        await page.locator(".share-button").click();
        await expect(page.locator(".modal-dialog")).toBeVisible();

        expect(await linkField(page).inputValue()).toBe(first);
    });

    test("shares a playlist, and a guest gets it in the reader's own order", async ({ page, context }) => {
        /*
         * PLAYLIST SHARES, end to end, and the assertion that needs the whole
         * journey: a playlist's ORDER is its content, so the one way to be sure the share space
         * did not re-sort it into album order is to read the maker's list and the guest's queue
         * and compare them. ShowShareTest pins the same rule server-side with a fixture built to
         * make the two orders disagree; this walks the path a reader actually takes.
         *
         * `Roadtrip` is the fixture's populated playlist and no spec mutates it (the reorder
         * tests use `Umsortieren`), so minting a link from it disturbs nothing. Minting does not
         * touch the playlist itself either way — the row it writes belongs to `shares`.
         */
        await page.goto("/playlists");
        await page.locator("li.playlist", { hasText: "Roadtrip" }).locator("a.playlist__link").click();
        await expect(page.locator(".hero-section")).toBeVisible();

        const owner = (await page.locator(".playlist-tracks__name").allTextContents()).map(text => text.trim());
        expect(owner.length).toBeGreaterThan(1);

        // The same button an album's hero wears, with the same modal behind it.
        await page.locator(".share-button").click();
        await expect(linkField(page)).toBeVisible();
        const id = (await linkField(page).inputValue()).split("/s/")[1]!;

        // A context with NO session, which is the whole feature — and the only honest way to
        // ask it, since this browser is signed in as the playlist's owner.
        const guest = await context.browser()!.newContext({ storageState: undefined });
        const guestPage = await guest.newPage();
        await guestPage.goto(`/s/${id}`);

        await expect(guestPage.getByRole("heading", { level: 2, name: "Roadtrip" })).toBeVisible();
        // The queue IS the page here (the share page draws NowPlayingSection), so these rows are
        // what the link plays, in the order it will play them.
        await expect(guestPage.locator(".np-queue .play-queue__row")).toHaveCount(owner.length);
        const heard = (await guestPage.locator(".np-queue .play-queue__name").allTextContents()).map(text =>
            text.trim()
        );
        expect(heard).toStrictEqual(owner);

        // A playlist is not a record, so the hero fans its sleeves rather than claiming one
        // cover — the same shape the signed-in page draws.
        await expect(guestPage.locator(".cover-sleeves")).toBeVisible();

        await guest.close();
    });

    test("re-activates an expired link from the word that says it expired", async ({ page, context }) => {
        /*
         * WHAT MAKES THE THIRTY-DAY GRACE PERIOD MEAN SOMETHING, walked
         * the way a reader walks it: find the dead row, press the word "abgelaufen", confirm, and
         * watch it come back up into the live list — with its copy button, which a dead row has
         * none of.
         *
         * THE FIXTURE IS ITS OWN (E2ESeeder::RENEWABLE_SHARE, an album). The other dead seeded
         * link is what `guest/share.spec.ts` opens to see the "expired" page, and renewing that
         * one would break a spec in another project from a file that never names it.
         *
         * The guest visit at the end is the assertion no other layer can make: RenewShareTest
         * proves the row goes live, but only a browser with NO session proves the URL that was
         * already handed out plays again for the person holding it.
         */
        const album = "The Bends";

        await page.goto("/dashboard/shared");
        const dead = page.locator(".shares--expired .shares__row", { hasText: album });
        await expect(dead).toHaveCount(1);
        // Nothing to copy while it is dead — pasting a 404 into a chat window is worse than
        // having no button.
        await expect(dead.locator(".shares__copy")).toHaveCount(0);

        await dead.locator(".shares__renew").click();
        await expect(page.locator("dialog.modal-dialog")).toBeVisible();
        await page.getByRole("button", { name: /^Wieder aktivieren$/u }).click();

        // Up into the live half, copyable again…
        const live = page.locator(".shares--active .shares__row", { hasText: album });
        await expect(live).toHaveCount(1);
        await expect(live.locator(".shares__copy")).toHaveCount(1);
        await expect(page.locator(".shares--expired .shares__row", { hasText: album })).toHaveCount(0);

        // …and working for whoever was already holding the link.
        const guest = await context.browser()!.newContext({ storageState: undefined });
        const guestPage = await guest.newPage();
        await guestPage.goto(`/s/${RENEWABLE_SHARE}`);

        await expect(guestPage.getByRole("heading", { level: 2, name: album })).toBeVisible();
        await expect(guestPage.locator(".np-queue .play-queue__row").first()).toBeVisible();
        await guest.close();
    });

    test("offers no share button on a genre", async ({ page }) => {
        // Deliberate: "listen to this genre" is a different kind of act.
        // The two verbs beside it are still there, so this is an absence and not a broken row.
        await openFirstRow(page, "/music/genres");

        await expect(page.locator(".subject-actions__play")).toBeVisible();
        await expect(page.locator(".subject-actions__enqueue")).toBeVisible();
        await expect(page.locator(".share-button")).toHaveCount(0);
    });

    test("revokes a link, and the link stops working", async ({ page, context }) => {
        /*
         * THE WHOLE POINT OF REVOKING, end to end, and the one assertion no other layer can
         * make: DestroyShareTest proves the row goes and that `/s/{id}` then 404s, but only a
         * browser walks the journey a reader actually takes — mint from a hero, find the link
         * in a list that had to be reachable, confirm a dialog, and find it gone.
         *
         * IT MINTS ITS OWN, deliberately: the two seeded links are what `guest/share.spec.ts`
         * follows, and revoking one here would break that spec from a file it never touches —
         * the exact failure an account per spec exists to prevent, arriving by another road.
         * A genre has no share button, so an ALBUM is minted; the same press the first test
         * in this file makes.
         */
        await openFirstRow(page, "/music/albums");
        const album = (await pageHeading(page).textContent())!.trim();

        await page.locator(".share-button").click();
        await expect(linkField(page)).toBeVisible();
        const id = (await linkField(page).inputValue()).split("/s/")[1]!;

        // The row is found by the ALBUM's name, not by position: this account holds the two
        // seeded links as well, and the point of a per-row button is revoking the right one.
        // In the LIVE half specifically — the page splits the two, and a link
        // minted a second ago belongs on top.
        await page.goto("/dashboard/shared");
        const row = page.locator(".shares--active .shares__row", { hasText: album });
        await expect(row).toHaveCount(1);

        await row.locator(".shares__revoke").click();
        await expect(page.locator("dialog.modal-dialog")).toBeVisible();
        await page.getByRole("button", { name: /^Zurückziehen$/u }).click();

        // Gone from the list — and NOT moved into the expired half below it. Revoking deletes
        // the row, so the link leaves the page rather than
        // joining the links that ran out of days; that half is about the calendar, not about a
        // decision its owner made.
        await expect(row).toHaveCount(0);
        await expect(page.locator(".shares--expired .shares__row", { hasText: album })).toHaveCount(0);

        // …and gone for EVERYBODY, which is the assertion worth the browser. A fresh context
        // with no session is the only honest way to ask it — the same 404 a typo gets, because
        // revoking deletes the row and every `/s/` route binds it.
        const guest = await context.browser()!.newContext({ storageState: undefined });
        const response = await (await guest.newPage()).goto(`/s/${id}`);
        expect(response?.status()).toBe(404);
        await guest.close();
    });

    test("gives the owner their queue panel back when they leave their own link", async ({ page }) => {
        /*
         * THE OWNER'S ROUND TRIP, which is the one journey neither guest/share.spec.ts nor any
         * unit test can make: the guest project has no session to come back into the app with,
         * and the panel's presence is a Vue lifecycle fact rather than a flag anything renders.
         *
         * It is a LAYOUT SWAP in both directions, and Vue mounts the incoming layout before
         * unmounting the outgoing one — so a naive registration would land the wrong way up
         * exactly once, on the way back. That is what this asserts (notePlayQueuePanel).
         */
        await openFirstRow(page, "/music/albums");
        await page.locator(".subject-actions__play").click();
        await expect(page.locator(".play-queue-toggle")).toBeVisible();

        await page.goto(`/s/${LIVE_SHARE}`);

        // The share space keeps the player and drops the panel: the queue is on the page there.
        await expect(page.locator(".play-queue-toggle")).toHaveCount(0);
        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".np-queue")).toBeVisible();

        await page.goto("/music/albums");

        await expect(page.locator(".play-queue-toggle")).toBeVisible();
    });
});
