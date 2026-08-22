import { expect, test } from "@playwright/test";
import { enqueueFromHero, pageHeading, stopQueueSync } from "../support/actions";
import { SEED_USER, SPEC_USERS } from "../support/environment";

/*
 * What signing out does to the player, which nothing but a real browser can answer.
 *
 * THE BUG THIS PINS. FullLayout is Inertia's persistent layout and logging out is
 * a client-side visit, so `setup()` does not run again: the queue module is a singleton, its
 * one-shot `hydrate()` guard was still set, and the previous reader's tracks simply stayed in
 * memory. The player bar carried on offering them, the header carried on offering the queue
 * toggle, and every row pointed at a stream behind `auth` — a player that had silently stopped
 * working rather than one that had gone away.
 *
 * AND THE HALF THAT WOULD HAVE BEEN WORSE. `clear()` is the obvious fix and is the wrong one:
 * it commits, so it stores an empty queue under `userId: null` over the copy that is the only
 * thing bringing the queue back, and — run a moment sooner, while the session was still alive —
 * would push the emptiness to `player_states` as well. What is asserted below is therefore not
 * only that the queue goes, but that the SERVER's copy survives it: the second half signs in
 * again with localStorage wiped, so the tracks that come back can have come from nowhere else.
 *
 * WHY THE GUEST PROJECT AND A REAL LOGIN. This spec signs OUT, which kills the session it is
 * using — a parked `storageState` would be dead for anything else that read it. Living here it
 * has no stored session to spoil, and it signs in for real because signing out is the thing
 * under test. Its account is its own for the ordinary reason (see SPEC_USERS): it leaves a
 * queue behind, and the queue follows the user.
 *
 * ONE TEST, AND TWO LOGINS. Fortify throttles login at five attempts per minute per username;
 * this account's only other login is the (unused) one auth.setup mints, so three of five. Split
 * across two tests it would be five, with no room for a retry.
 */

const LOGOUT_USER = { name: SPEC_USERS.logout, password: SEED_USER.password };

test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

test("signing out takes the queue, the player and the toggle with it — and leaves the stored copy alone", async ({
    page
}) => {
    await signInAndQueueASong();

    const bar = page.locator(".player-bar");
    const toggle = page.locator(".play-queue-toggle");
    await expect(bar).toBeVisible();
    await expect(toggle).toBeVisible();

    // POLLED, because queue writes are coalesced behind a 500ms trailing timer — read once,
    // straight after the enqueue, this is legitimately still empty.
    await expect
        .poll(() =>
            page.evaluate(
                () => (JSON.parse(localStorage.getItem("mixtape.queue") ?? '{"tracks":[]}').tracks as unknown[]).length
            )
        )
        .toBe(1);

    // Out. The flush on the press is what gets the queue to the server while there is still a
    // session to accept it (see UserMenu), so this is also the moment the row is written.
    await page.getByRole("button", { name: "Benutzermenü öffnen" }).click();
    // A button, not a link: logout is a POST, so the <Link> renders `as="button"`.
    await page.getByRole("button", { name: "Abmelden" }).click();
    await page.waitForURL(/\/(login)?$/u);

    // All three, and the footer back in the bar's place — they are alternatives, not neighbours.
    await expect(bar).toHaveCount(0);
    await expect(toggle).toHaveCount(0);
    await expect(page.locator(".play-queue")).toHaveCount(0);
    await expect(page.locator("footer")).toBeVisible();

    /*
     * WIPE THE BROWSER'S COPY, which is what makes the rest of this test mean something: with
     * localStorage gone, a queue that comes back can only have come from `player_states`. So
     * what follows is an assertion about the SERVER's row surviving the sign-out — the flush at
     * the press put the real queue there, and nothing after it may put an empty one over it.
     *
     * The localStorage half of the same rule cannot be asserted here for the obvious reason
     * (this line destroys the evidence) and does not need to be: it is `usePlayerQueue`'s own
     * spec, "leaves the departing reader's stored queue exactly as it was".
     */
    await page.evaluate(() => localStorage.clear());

    await signIn(LOGOUT_USER);
    // A FULL page load, deliberately: `playerState` is a shared prop that HandleInertiaRequests
    // withholds from an Inertia visit, so the server's copy rides down on a document request and
    // nowhere else.
    await page.goto("/dashboard");

    await expect(page.locator(".player-bar")).toBeVisible();
    await expect(page.locator(".play-queue-toggle")).toBeVisible();

    /** Sign in through the real form. Local, because `signIn` from the helpers needs the page. */
    async function signIn(user: { name: string; password: string }): Promise<void> {
        await page.goto("/login");
        await page.locator("#name").fill(user.name);
        await page.locator("#password").fill(user.password);
        await page.getByRole("button", { name: /^Anmelden$/u }).click();
        // Where a login lands follows the library now, and this one is mostly music.
        await page.waitForURL(/\/music/u);
    }

    /** Sign in and put exactly one song in the queue, through the UI a reader would use. */
    async function signInAndQueueASong(): Promise<void> {
        await signIn(LOGOUT_USER);
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toBeVisible();
        await enqueueFromHero(page);
    }
});
