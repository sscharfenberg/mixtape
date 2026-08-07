import path from "node:path";
import { expect, test as setup } from "@playwright/test";
import { signIn } from "./actions";
import { SEED_USER, SPEC_USERS, repoRoot, specStorageState } from "./environment";

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

/**
 * A session for each account that owns a play queue (see SPEC_USERS).
 *
 * Five real logins a run in total, and that is inside Fortify's throttle rather than close
 * to it: the limit is five per minute PER USERNAME (`Limit::perMinute(5)->by(name|ip)`), so
 * five different names are five buckets of one attempt each. The cache is cleared before
 * every run regardless (see resetRateLimiter).
 *
 * Signed in for real, like the account above, for the same reason: a broken login should
 * fail loudly here rather than as a wall of redirect failures downstream.
 */
setup("mint a session for every spec that owns a queue", async ({ browser }) => {
    for (const spec of Object.keys(SPEC_USERS) as Array<keyof typeof SPEC_USERS>) {
        const context = await browser.newContext({ storageState: undefined });
        const page = await context.newPage();

        await signIn(page, { name: SPEC_USERS[spec], password: SEED_USER.password });
        await context.storageState({ path: specStorageState(spec) });
        await context.close();
    }
});
