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

    test("gives every jump-link a section to land on", async ({ page }) => {
        /*
         * IT COUNTS FIVE, NOT FOUR: the four settings sections plus "your
         * shared content", which is drawn only for a reader who has shared something. The
         * seeded account has (E2ESeeder mints the two links the guest spec follows), so this
         * is the with-shares shape — and that the OTHER shape exists is asserted in
         * DashboardPage.test.ts, where an account with no shares is one prop away.
         *
         * The resolve loop is the half that matters and is why the count is worth having at
         * all: a jump-link whose anchor is not on the page scrolls nowhere and reads as a
         * broken control, which is exactly what a conditional section invites.
         */
        const links = page.locator(".sticky-nav a");
        await expect(links).toHaveCount(5);
        await expect(page.locator("#sharesSection")).toHaveCount(1);

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
         * password check lives in DeleteAccountRequest, so a ValidationException produces that
         * body where a hand-built `response()->json()` otherwise would; the shapes agree, and
         * this is what says so.
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

test.describe("the reader's share links", () => {
    /*
     * `/dashboard/shared` — the list, and the only place a link can be revoked. What the server
     * decides is pinned in tests/Feature/Shares/RevokeShareTest.php and the revoke JOURNEY in
     * tests/e2e/app/share.spec.ts; what is left here is the one thing neither can see, and it
     * is CSS.
     */
    test("keeps both row controls against the trailing edge, wrapped or not", async ({ page }) => {
        /*
         * THE BUG THIS GUARDS is the arrangement it replaced: the two buttons were loose flex
         * items laid out by whatever space the facts left them, and the auto margin that pushed
         * them right sat on the VALIDITY beside them, behind a breakpoint. A long enough name
         * wrapped the row onto two lines and the pair went with it — landing wherever the
         * second line happened to end, which is nowhere a reader's hand looks. They are one
         * flex item with an auto margin of their own now.
         *
         * MEASURED AT BOTH WIDTHS AND COMPARED TO EACH OTHER rather than to the row's padding:
         * the row carries a border, so the gap is padding + border + whatever subpixel rounding
         * a fractional layout leaves. What matters is that it does not CHANGE.
         */
        await page.goto("/dashboard/shared");
        await expect(page.locator(".shares__row").first()).toBeVisible();

        const gap = () =>
            page.evaluate(() => {
                const row = document.querySelector(".shares__row")!;
                const controls = row.querySelector(".shares__controls")!;

                return {
                    toEdge: Math.round(row.getBoundingClientRect().right - controls.getBoundingClientRect().right),
                    height: Math.round(row.getBoundingClientRect().height)
                };
            });

        const wide = await gap();

        // FORCE THE WRAP. The seeded shares are called "OK Computer" — short enough to sit on
        // one line at any width this app is used at, so measuring without this proves nothing
        // about the case the change was made for.
        await page.evaluate(() => {
            document.querySelector(".shares__name")!.textContent =
                "The Powers That B / Niggas On The Moon / Jenny Death — Deluxe Anniversary Edition";
        });
        await page.setViewportSize({ width: 420, height: 900 });
        const wrapped = await expect
            .poll(async () => (await gap()).height)
            .toBeGreaterThan(wide.height)
            .then(gap);

        // Same distance from the edge on two lines as on one, which is the whole assertion.
        expect(wrapped.toEdge).toBe(wide.toEdge);
    });
});
