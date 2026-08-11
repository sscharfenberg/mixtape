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
 * Minting writes a row, but shares belong to whoever made them and nothing else in the suite
 * reads them, so this shares the default account rather than owning one.
 */

/** Open the first row of a listing, which is how every detail page here is reached. */
const openFirstRow = async (page: Page, listing: string): Promise<void> => {
    await page.goto(listing);
    await page.locator("tbody tr").first().click();
    await expect(page.locator(".hero-section")).toBeVisible();
};

/** The link field inside the modal. */
const linkField = (page: Page) => page.locator(".share-modal__link");

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

    test("offers no share button on a genre", async ({ page }) => {
        // The owner's call (2026-08-11): "listen to this genre" is a different kind of act.
        // The two verbs beside it are still there, so this is an absence and not a broken row.
        await openFirstRow(page, "/music/genres");

        await expect(page.locator(".subject-actions__play")).toBeVisible();
        await expect(page.locator(".subject-actions__enqueue")).toBeVisible();
        await expect(page.locator(".share-button")).toHaveCount(0);
    });
});
