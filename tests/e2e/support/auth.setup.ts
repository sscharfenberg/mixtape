import path from "node:path";
import { expect, test as setup } from "@playwright/test";
import { signIn } from "./actions";
import { repoRoot } from "./environment";

/** Where the signed-in session is parked for the `app` project to reuse. */
const STATE = path.join(repoRoot, "tests/e2e/.auth/user.json");

/**
 * Sign in once and save the session, so the authenticated specs do not each pay for a
 * login round trip.
 *
 * Done through the real form rather than by minting a session cookie: this is also the
 * only place the login flow is exercised end to end for a user WITHOUT two-factor, and
 * doing it for real means a broken login fails the whole suite loudly here instead of
 * showing up as thirty confusing redirect failures downstream.
 */
setup("sign in as the seeded user", async ({ page }) => {
    // useLogin posts JSON and then hands off to router.visit, so signIn() asserts on
    // where we land rather than on a form submission.
    await signIn(page);

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();

    await page.context().storageState({ path: STATE });
});
