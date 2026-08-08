import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * Making a playlist, in a real engine.
 *
 * The server side is pinned by CreatePlaylistTest — what validates, what is stored, where
 * the redirect goes — and the listing's rendering by PlaylistsPage.test.ts (including the
 * empty state, which needs no browser). Three things here are unavailable to either, and
 * all three are what a reader actually does:
 *
 *   - THE WHOLE JOURNEY AS ONE MOVE: the listing offers the form, the form posts, the
 *     app lands back on the listing with the new playlist on it and a toast saying so.
 *     Each half is proven separately; that they meet is not.
 *   - VALIDATE-ON-BLUR. The name field is checked through Precognition on `change`, which
 *     needs a real focus/blur cycle and a real fetch. This is the field where it earns its
 *     place — names are unique per owner, so "you already have one called that" arrives
 *     while the reader is still in the form rather than after they submit it.
 *   - THE NATIVE MAXLENGTH on both fields. Only a browser enforces it, and it is the
 *     reason a reader never meets the server's length rules at all.
 *
 * SEQUENTIAL, IN ONE WORKER. `fullyParallel` parallelises at the TEST level, so without
 * this the tests below would run concurrently against the one account they share — and the
 * row this file creates is the row a later test opens the menu on.
 *
 * NOTHING HERE ASSUMES AN EMPTY OR A FRESH DATABASE, which it did at first and which cost
 * a diagnosis: every name is suffixed with a per-invocation stamp, so a second run against
 * a server that is still up — or a `--repeat-each` — cannot collide with the playlists the
 * previous one left behind. A test whose fixture is "the seeder just ran" is a test that
 * only passes the first time.
 */
test.describe.configure({ mode: "default" });

/**
 * Suffix making every name in this run unique.
 *
 * The seeded database is normally reset per invocation, so this looks redundant — and is
 * not: `reuseExistingServer` keeps a server (and its data) alive between runs, and a name
 * clash surfaces as the create form simply refusing to redirect, which reads as a broken
 * redirect rather than as "that playlist already exists".
 */
const STAMP = Date.now().toString(36);
const NIGHT_DRIVE = `E2E Nachtfahrt ${STAMP}`;
const DUPLICATE = `E2E Doppelt ${STAMP}`;
const DISCARDED = `E2E Verworfen ${STAMP}`;

/**
 * Press "create" and wait for the POST itself to come back.
 *
 * NOT just a click followed by an assertion on the rendered result, and the reason is a
 * number rather than a preference: this is the only spec in the suite that WRITES through
 * the app on nearly every test, the run shares one sqlite file across three workers, and
 * the environment gives a blocked writer a 5000ms busy timeout to get its lock. That is
 * exactly Playwright's default `expect` timeout — so a write that legitimately waited for a
 * reader would finish just after the assertion gave up, and the failure read as "the
 * validation error never appeared" on a different test each run. Waiting for the response
 * bounds this by the test timeout instead, which is what the lock wait needs.
 */
const submitPlaylistForm = async (page: Page): Promise<void> => {
    const posted = page.waitForResponse(
        response => response.url().endsWith("/playlists") && response.request().method() === "POST"
    );

    await page.getByRole("button", { name: /^Wiedergabeliste anlegen$/u }).click();
    await posted;
};

/** Fill the form and create a playlist, ending up back on the listing. */
const createPlaylist = async (page: Page, name: string, description?: string): Promise<void> => {
    await page.goto("/playlists/create");
    await page.locator("#name").fill(name);
    if (description) await page.locator("#description").fill(description);
    await submitPlaylistForm(page);
    await page.waitForURL(/\/playlists$/u);
};

test.describe("the playlists area", () => {
    test("offers the create form from the listing", async ({ page }) => {
        await page.goto("/playlists");

        await page.getByRole("link", { name: /Neue Wiedergabeliste anlegen/u }).click();

        await page.waitForURL(/\/playlists\/create$/u);
        await expect(page.locator("#name")).toBeVisible();
    });

    test("creates a playlist, lands back on the listing and says it did", async ({ page }) => {
        await createPlaylist(page, NIGHT_DRIVE, "Für die Autobahn um zwei.");

        // Located BY its text, not by being the first toast on screen: toasts last three
        // seconds, so a neighbouring test's can still be up when this one looks.
        const toast = page.locator(".toast-container__item--success", { hasText: NIGHT_DRIVE });
        await expect(toast).toBeVisible();

        // And the playlist is on the page, with the zero tracks a new one has.
        const row = page.locator("li.playlist", { hasText: NIGHT_DRIVE });
        await expect(row).toBeVisible();
        await expect(row.locator(".playlist__title")).toHaveText(NIGHT_DRIVE);
        await expect(row.locator(".playlist__description")).toHaveText("Für die Autobahn um zwei.");
        await expect(row.getByText("Titel", { exact: true })).toBeVisible();

        // A brand-new playlist has neither a playtime nor a change to report.
        await expect(row.getByText("Dauer", { exact: true })).toHaveCount(0);
        await expect(row.getByText("Geändert", { exact: true })).toHaveCount(0);
    });

    test("opens the row's menu, which the row's own link must not swallow", async ({ page }) => {
        // A <button> inside an <a> renders fine and misbehaves: the press would follow the
        // link as well as open the panel. Only a real engine shows that, and the assertion
        // that catches it is that the menu opens and the page stays put.
        await page.goto("/playlists");
        const row = page.locator("li.playlist", { hasText: NIGHT_DRIVE });

        await row.locator(".popover button").click();

        await expect(row.getByRole("link", { name: /^Bearbeiten$/u })).toBeVisible();
        await expect(page).toHaveURL(/\/playlists$/u);
    });

    test("says a name is taken while the reader is still in the field", async ({ page }) => {
        // Arrange the clash through the form itself, so nothing here depends on a fixture.
        await createPlaylist(page, DUPLICATE);

        await page.goto("/playlists/create");
        await page.locator("#name").fill(DUPLICATE);

        // Blur, which is what fires the precognitive check — no submit anywhere here. Its
        // response is awaited for the same reason the submit helper awaits its own: the
        // assertion below must outlast a round trip on a loaded server, not race it.
        const checked = page.waitForResponse(
            response =>
                response.url().endsWith("/playlists") &&
                response.request().method() === "POST" &&
                response.request().headers().precognition === "true"
        );
        await page.locator("#description").focus();
        await checked;

        await expect(page.getByText("Du hast bereits eine Wiedergabeliste mit diesem Namen.")).toBeVisible();
        // Still on the form, with nothing posted for real.
        await expect(page).toHaveURL(/\/playlists\/create$/u);
    });

    test("will not submit a nameless playlist", async ({ page }) => {
        await page.goto("/playlists/create");
        await submitPlaylistForm(page);

        await expect(page.getByText("Bitte gib der Wiedergabeliste einen Namen.")).toBeVisible();
        await expect(page).toHaveURL(/\/playlists\/create$/u);
    });

    test("caps both fields in the browser, so the server's length rules are never met", async ({ page }) => {
        // maxlength is a UA behaviour; happy-dom does not enforce it.
        await page.goto("/playlists/create");

        await page.locator("#name").fill("x".repeat(300));
        await page.locator("#description").fill("y".repeat(1200));

        await expect(page.locator("#name")).toHaveValue("x".repeat(255));
        await expect(page.locator("#description")).toHaveValue("y".repeat(1000));
    });

    test("lets the reader back out to the listing without creating anything", async ({ page }) => {
        await page.goto("/playlists/create");
        await page.locator("#name").fill(DISCARDED);

        await page.getByRole("link", { name: /^Abbrechen$/u }).click();
        await page.waitForURL(/\/playlists$/u);

        await expect(page.locator("li.playlist", { hasText: DISCARDED })).toHaveCount(0);
    });
});
