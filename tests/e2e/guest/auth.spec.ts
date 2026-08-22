import { expect, test } from "@playwright/test";
import { signIn } from "../support/actions";
import { SEED_USER } from "../support/environment";

/*
 * The auth gate, from a browser with no session.
 *
 * This is the pair of questions the PHP feature tests cannot fully answer on their own:
 * they prove the middleware redirects, but not that a real browser following that
 * redirect ends up on a page that actually boots — with its assets, its Vue app mounted
 * and its form usable. A misconfigured CSP or a stale manifest passes every PHP test and
 * leaves a blank page here.
 *
 * BUDGET: Fortify throttles login at five attempts per minute per `username|ip`, and a
 * failed attempt counts. This file performs three real logins and the stored-session setup
 * performs a fourth — so there is room for exactly one more before runs start failing with
 * a 429 that presents as "the form does nothing". Global setup clears the limiter at the
 * start of every run (see support/environment.ts → resetRateLimiter); adding a fifth login
 * test would need the limit raised for this environment instead.
 */

test.describe("the auth gate", () => {
    test("sends a guest from a protected page to the login form", async ({ page }) => {
        await page.goto("/dashboard");

        await expect(page).toHaveURL(/\/login/u);
        await expect(page.locator("#name")).toBeVisible();
    });

    test("keeps the music library behind the gate", async ({ page }) => {
        await page.goto("/music/songs");

        await expect(page).toHaveURL(/\/login/u);
    });

    test("actually boots the Vue app on the login page", async ({ page }) => {
        // The cheapest possible canary for "assets loaded at all": if the manifest were
        // stale or public/hot pointed at a dead dev server, nothing below would render
        // and every other spec would fail for a reason that looks nothing like the cause.
        const errors: string[] = [];
        page.on("pageerror", error => errors.push(error.message));

        await page.goto("/login");

        await expect(page.getByRole("button", { name: /^Anmelden$/u })).toBeEnabled();
        expect(errors).toEqual([]);
    });

    test("rejects a wrong password without leaving the page", async ({ page }) => {
        await signIn(page, { name: SEED_USER.name, password: "definitiv-falsch" }, null);

        // The error renders in place — useLogin posts JSON precisely so a failed attempt
        // does not cost a full page visit. Matched on the one distinctive word of
        // Laravel's `auth.failed` rather than the whole sentence, which is prose and
        // gets reworded.
        await expect(page.getByText(/Zugangsdaten/iu).first()).toBeVisible();
        await expect(page).toHaveURL(/\/login/u);
    });

    test("signs in and lands where the music is", async ({ page }) => {
        // NOT the dashboard, which is a page about the account. The destination is decided by
        // what the library holds (App\Services\Auth\LandingPage) and the seeded collection is
        // mostly music; the four answers that rule can give are pinned in LandingPageTest, where
        // a library is three lines rather than a fixture.
        await signIn(page);

        await expect(page).toHaveURL(/\/music/u);
    });

    test("lets a signed-in user back out again", async ({ page }) => {
        await signIn(page);

        await page.getByRole("button", { name: "Benutzermenü öffnen" }).click();
        // A button, not a link: logout is a POST, so the <Link> renders `as="button"`.
        await page.getByRole("button", { name: "Abmelden" }).click();

        await page.waitForURL(/\/(login)?$/u);
        // And the session is really gone, not just the page changed.
        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/login/u);
    });
});
