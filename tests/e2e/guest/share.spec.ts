import { expect, test } from "@playwright/test";
import { audioState, pageHeading } from "../support/actions";

/*
 * A SHARE LINK OPENED BY SOMEBODY WITH NO ACCOUNT — the feature the whole app is
 * internet-facing for (docs/sharing.md).
 *
 * ONE OF THE FEW SPECS THAT BELONGS IN `guest/`, and the reason is the whole point of the
 * feature: everything else here is either behind `auth` or is the login form in front of it,
 * while this is a page a stranger is meant to reach. The project runs with no stored session
 * at all, so a session leaking in could not make these pass by accident.
 *
 * WHAT ONLY A BROWSER CAN ANSWER, and what the other two layers therefore leave here:
 *
 *   - THAT A GUEST REALLY HEARS SOMETHING. The stream route under `/s/` is new, it is
 *     unauthenticated, and it feeds a real <audio> element under the app's own CSP —
 *     `media-src 'self'`. happy-dom has no decoder and no network behind an `<audio src>`,
 *     and the PHP suite proves the bytes leave the server, not that the element takes them.
 *   - THAT NOTHING ON THE PAGE BOUNCES TO THE LOGIN FORM. Every URL the page hands out is
 *     rewritten into the share's own space by ShareGrant; one that is missed plays fine for the
 *     signed-in reader testing the link and redirects everybody else. Only a browser follows them.
 *   - THAT THE PAGE BOOTS AT ALL for a signed-out reader. It renders in a layout of its own
 *     (ShareLayout) whose header draws no site menu without a user — a component that assumed
 *     one would throw during setup and leave a blank page, which no unit mount would show.
 *   - THAT THE PLAYER'S OWN ROWS SURVIVE THE TRIP OUT OF THE APP. The page fills the queue on
 *     arrival and draws NowPlayingSection rather than a listing of its own, and every part of
 *     that block is otherwise only ever rendered behind `auth` — the queue rows, the neighbour
 *     cards and the
 *     visualiser all reach for things (an analyser, Sortable, a genre map) that a guest either
 *     lacks or is never sent.
 *
 * The link ids are the fixture's (E2ESeeder → LIVE_SHARE / EXPIRED_SHARE), because minting is
 * behind `auth` and this project has no account to mint with. What the server decides —
 * the grant, the seven days, the 404s — is pinned in tests/Feature/Shares/, where it is cheap.
 */

/** The seeded links. Literals on both sides: a spec cannot call PHP for a constant. */
const LIVE = "019e0007-0000-7000-8000-000000000001";
const EXPIRED = "019e0007-0000-7000-8000-000000000002";

/** The <audio> element's own state, read out of the page rather than from what the UI claims. */
test.describe("a share link, with no account", () => {
    test("opens the page and says what it is", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // Not the login form — the whole feature in one assertion.
        await expect(page).toHaveURL(new RegExp(`/s/${LIVE}$`, "u"));
        // `pageHeading`, not the first heading on the page: the document's <h1> is the
        // wordmark in AppHeader, which this layout keeps.
        await expect(page.locator(".hero-section")).toBeVisible();
        await expect(pageHeading(page)).toContainText("OK Computer");
    });

    test("arrives with the whole link queued, and nothing playing", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // THE PAGE FILLS THE QUEUE ITSELF, which is why it needs no track listing of its own:
        // the rows below the hero ARE the queue. What a guest was sent is the album, so all
        // twelve are there with the first one cued.
        await expect(page.locator(".np-queue .play-queue__row")).toHaveCount(12);
        await expect(page.locator(".np-queue .play-queue__row--current")).toHaveCount(1);

        // The bar replaces the footer as soon as a track is LOADED, which is now on arrival —
        // and loading is not playing: a browser would refuse a page that started on its own,
        // and this asserts we are not even asking.
        await expect(page.locator(".player-bar")).toBeVisible();
        expect((await audioState(page)).paused).toBe(true);
    });

    test("has no queue panel, and no header button offering one", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // Deliberate: the panel is a signed-in reader's affordance, and this space has
        // its queue on the page. Both assertions matter — the panel being absent is the
        // layout's decision, and the button being absent is the header FOLLOWING that decision
        // rather than restating it. A toggle left behind would open nothing at all.
        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".play-queue-toggle")).toHaveCount(0);

        // …and the key that does the same job is inert here for the same reason.
        await page.keyboard.press("q");
        await expect(page.locator(".play-queue")).toHaveCount(0);
    });

    test("plays, from a stream route that needs no session", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // Repeat is not available to a guest and the fixture's audio is one second long, so
        // the assertion is made while the FIRST track is still running: play, then read the
        // element immediately rather than waiting on a position.
        await page.locator(".share__play").click();

        await expect.poll(async () => (await audioState(page)).src).toContain(`/s/${LIVE}/tracks/`);
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
    });

    test("starts at the row a guest presses, and keeps the rest", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // The row's hit area is a transparent button over the whole of it (QueueList), so this
        // is the same press a reader makes anywhere in the row.
        await page.locator(".np-queue .play-queue__row").nth(3).locator(".play-queue__load").click();

        await expect(page.locator(".np-queue .play-queue__row").nth(3)).toHaveClass(/play-queue__row--current/u);
        await expect(page.locator(".np-queue .play-queue__row")).toHaveCount(12);
    });

    test("names the track either side, which is the point of the block", async ({ page }) => {
        await page.goto(`/s/${LIVE}`);

        // The neighbour cards are the reason the Now Playing block came across at all. At the
        // head of the queue there is nothing behind, and the next card names track two — which
        // it can only do by reading the same queue the rows below are drawn from.
        await expect(page.locator(".neighbour--previous")).toContainText("Nichts davor");
        await expect(page.locator(".neighbour--previous .neighbour__step")).toBeDisabled();

        const second = await page.locator(".np-queue .play-queue__name").nth(1).textContent();

        await expect(page.locator(".neighbour--next .neighbour__title")).toHaveText(second!);
    });

    test("hands out no URL that leads back into the app", async ({ page }) => {
        const redirects: string[] = [];
        page.on("response", response => {
            if (response.status() >= 300 && response.status() < 400) redirects.push(response.url());
        });

        await page.goto(`/s/${LIVE}`);
        await page.locator(".share__play").click();
        await expect.poll(async () => (await audioState(page)).src).toContain("/s/");

        // A `/music/…` URL on a queue entry is the failure this guards: it plays for a signed-in
        // testing their own link and redirects a guest to the login form, which is precisely
        // the case no unit test and no PHP test sees.
        expect(redirects.filter(url => url.includes("/login"))).toStrictEqual([]);
    });

    test("says so, kindly, when the link has expired", async ({ page }) => {
        await page.goto(`/s/${EXPIRED}`);

        await expect(page.locator(".card")).toContainText("abgelaufen");
        // Nothing to press: a play button over an empty queue reads as the page being broken
        // rather than as the link being over, and the player's rows go with it.
        await expect(page.locator(".share__play")).toHaveCount(0);
        await expect(page.locator(".now-playing-section")).toHaveCount(0);
    });

    test("is an ordinary 404 for a link that was revoked", async ({ page }) => {
        // Revoking DELETES the row, so an unused id is exactly what a revoked link looks
        // like — indistinguishable from a typo, which is the intent.
        const response = await page.goto("/s/019e0007-0000-7000-8000-000000009999");

        expect(response?.status()).toBe(404);
    });

    test("keeps the library itself behind the gate", async ({ page }) => {
        // The share space is an addition, not a widening: holding a link must not make
        // /music reachable. Cheap to assert, and the assertion this whole URL design exists
        // to keep true.
        await page.goto(`/s/${LIVE}`);
        await page.goto("/music/albums");

        await expect(page).toHaveURL(/\/login/u);
    });
});
