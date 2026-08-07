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

    test("still has an account to come back to", async ({ page }) => {
        // The guard on every test above: none of them may actually delete, because the whole
        // signed-in suite shares this one session.
        await page.reload();

        await expect(page).toHaveURL(/\/dashboard/u);
        await expect(page.locator("#profileSection")).toBeVisible();
    });
});
