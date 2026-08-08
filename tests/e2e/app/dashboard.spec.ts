import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * The settings dashboard, in a real engine.
 *
 * What each section decides is covered by Vitest — which form shows for which 2FA state,
 * which handler a submitter dispatches to, that the delete button only ASKS. Three things
 * here are structurally unavailable to happy-dom, and all three are the parts a reader
 * actually feels:
 *
 *   - THE MODAL IS A NATIVE <dialog> OPENED WITH showModal(). That puts it in the TOP LAYER,
 *     moves focus into it, and makes the rest of the page inert. happy-dom has no top layer
 *     and no inertness, so asserting any of it there would be asserting the fake. The
 *     property that matters is the one a keyboard user depends on: while the dialog is open,
 *     the page behind it cannot be reached.
 *   - ESCAPE. The dialog intercepts `cancel` to run its exit animation, and getting that
 *     wrong either closes it abruptly or — the worse failure — traps the reader in a modal
 *     that will not close. Only a browser dispatches a real `cancel`.
 *   - THE JUMP-NAV MOVES THE PAGE. useStickyNav is IntersectionObserver over section
 *     positions; there are no positions without layout.
 *
 * Nothing here submits the delete form. The run shares ONE stored session across every
 * signed-in spec, so a successful deletion would take the account out from under the rest of
 * the suite. The confirmation dialog is exercised right up to — and not including — the last
 * press, which is also the boundary the feature test on the server side covers from.
 */

/** Open the account-deletion confirmation and wait for the dialog to settle. */
const openDeleteModal = async (page: Page): Promise<void> => {
    await page.locator("#deleteSection").scrollIntoViewIfNeeded();
    await page.getByRole("button", { name: /Benutzerkonto löschen/u }).first().click();
    await expect(page.locator("dialog.modal-dialog")).toBeVisible();
};

test.describe("the settings dashboard", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/dashboard");
    });

    test("carries all four settings sections behind one jump-nav", async ({ page }) => {
        const links = page.locator(".sticky-nav a");
        await expect(links).toHaveCount(4);

        for (const href of await links.evaluateAll(nodes => nodes.map(node => node.getAttribute("href")!))) {
            await expect(page.locator(href)).toHaveCount(1);
        }
    });

    test("jumps to a section, which is what the nav is for", async ({ page }) => {
        // The anchors resolve in Vitest; that the page actually MOVES needs layout.
        const before = await page.evaluate(() => window.scrollY);

        await page.locator('.sticky-nav a[href="#deleteSection"]').click();
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(before);

        await expect(page.locator("#deleteSection")).toBeInViewport();
    });

    test("says the account is unprotected while two-factor auth is off, and offers the way on", async ({ page }) => {
        // The seeded account has no 2FA, so this is the state the badge should report — and
        // the section should be showing the ENABLE form rather than the disable one.
        await expect(page.locator("#twoFactorSection").locator("..").getByText(/Deaktiviert/u).first()).toBeVisible();
        await expect(page.getByRole("button", { name: /^2FA aktivieren$/u })).toBeVisible();
        await expect(page.getByRole("button", { name: /^2FA deaktivieren$/u })).toHaveCount(0);
    });

    test("asks before deleting anything, in a dialog the page behind cannot be reached through", async ({ page }) => {
        await openDeleteModal(page);

        // The top layer: this is the property happy-dom structurally cannot have.
        expect(
            await page.evaluate(() => {
                const link = document.querySelector<HTMLElement>(".sticky-nav a")!;
                const dialog = document.querySelector<HTMLDialogElement>("dialog.modal-dialog")!;

                // The element at the link's own centre point is inside the dialog's backdrop,
                // not the link — i.e. the modal really is over everything.
                const box = link.getBoundingClientRect();
                const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);

                return hit === null || dialog.contains(hit) || hit === dialog;
            })
        ).toBe(true);
    });

    test("puts the cursor in the password field, so the dialog can be answered by typing", async ({ page }) => {
        await openDeleteModal(page);

        await expect(page.locator("#delete-password")).toBeFocused();
    });

    test("keeps the confirmation locked until a password is typed", async ({ page }) => {
        await openDeleteModal(page);
        const confirm = page.locator(".modal-dialog__footer button[type='submit']");

        await expect(confirm).toBeDisabled();

        await page.locator("#delete-password").fill("irgendetwas");

        await expect(confirm).toBeEnabled();
    });

    test("lets Escape out again, rather than trapping the reader in it", async ({ page }) => {
        // The dialog PREVENTS the native cancel to run its exit animation; a mistake there
        // leaves a modal that swallows Escape and cannot be dismissed.
        await openDeleteModal(page);

        await page.keyboard.press("Escape");

        await expect(page.locator("dialog.modal-dialog")).toBeHidden();
        await expect(page).toHaveURL(/\/dashboard/u);
    });

    test("closes on the backdrop but not on the dialog's own content", async ({ page }) => {
        await openDeleteModal(page);

        // Inside the panel: nothing happens.
        await page.locator(".modal-dialog__body").click({ position: { x: 5, y: 5 } });
        await expect(page.locator("dialog.modal-dialog")).toBeVisible();

        // The backdrop is the dialog element itself, outside the content box.
        await page.locator("dialog.modal-dialog").click({ position: { x: 5, y: 5 } });
        await expect(page.locator("dialog.modal-dialog")).toBeHidden();
    });

    test("shows a wrong password inline, without deleting anything or moving the page", async ({ page }) => {
        /*
         * THE ONE SUBMIT THIS FILE MAKES, and it is safe precisely because it fails: a wrong
         * password deletes nothing, so the shared account survives.
         *
         * It earns a browser because the whole chain only exists here. useDeleteAccount drives
         * this with fetch() rather than an Inertia visit, so the server's 422 has to arrive as
         * JSON — which depends on `shouldRenderJsonWhen` matching `Accept: application/json`
         * (bootstrap/app.php) — and its `errors.password[0]` has to reach the form row. The
         * password check moved into DeleteAccountRequest on 2026-08-08, which means a
         * ValidationException now produces that body where a hand-built `response()->json()`
         * used to; the shapes agree, and this is what says so.
         *
         * The other half of the assertion is what must NOT happen: no navigation, no scroll,
         * no global error bag. That is the entire reason the modal uses fetch().
         */
        await openDeleteModal(page);

        await page.locator("#delete-password").fill("definitely-not-the-password");

        const answered = page.waitForResponse(
            response => response.url().endsWith("/user/delete") && response.request().method() === "DELETE"
        );
        // The same locator the "locked until a password is typed" test uses — the modal's
        // confirm reads "Löschen", not the section trigger's "Benutzerkonto löschen".
        await page.locator(".modal-dialog__footer button[type='submit']").click();
        const response = await answered;

        expect(response.status()).toBe(422);
        await expect(page.locator("dialog.modal-dialog .form-row__error")).toHaveText(
            /Das Passwort ist nicht korrekt\./u
        );

        // Still here, still signed in, modal still open.
        await expect(page.locator("dialog.modal-dialog")).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard/u);
    });

    test("still has an account to come back to", async ({ page }) => {
        // The guard on every test above: none of them may actually delete, because the whole
        // signed-in suite shares this one session.
        await page.reload();

        await expect(page).toHaveURL(/\/dashboard/u);
        await expect(page.locator("#profileSection")).toBeVisible();
    });
});
