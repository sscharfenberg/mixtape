import { expect, test } from "@playwright/test";

/*
 * The play queue's LAYOUT, which is the half no other test layer can see.
 *
 * Three things here need a real browser and a real viewport:
 *
 *   - the footer and the player bar are alternatives. Vitest can prove PlayerBar renders
 *     when a track is loaded, but not that the footer left with it.
 *   - the panel takes 240px out of <main>, and the DataTable's table-or-cards switch is a
 *     CONTAINER query on that remaining width. Whether a listing survives with the queue
 *     open is a question about layout, and happy-dom has none. This is the specific
 *     regression that made the breakpoint move from `desktop` to `landscape`.
 *   - the panel has two entirely different shapes — a column beside the page, and a bottom
 *     sheet over it — chosen by viewport width.
 *
 * The queue also has to survive an Inertia navigation, which is the reason it and the
 * player live in the layout rather than in a page.
 */

/** Open a song's page and put it in the queue. Returns the song's title. */
const enqueueFirstSong = async (page: import("@playwright/test").Page): Promise<string> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    const title = await page.locator("main h1").first().innerText();
    await page.locator(".hero-section__actions button").click();
    await expect(page.locator(".play-queue")).toBeVisible();

    return title;
};

test.describe("the play queue", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("shows nothing until something is queued", async ({ page }) => {
        await page.goto("/music/songs");

        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".player-bar")).toHaveCount(0);
        await expect(page.locator("footer")).toBeVisible();
    });

    test("replaces the footer with the player bar once a track is loaded", async ({ page }) => {
        await enqueueFirstSong(page);

        await expect(page.locator(".player-bar")).toBeVisible();
        // The two are alternatives, not neighbours.
        await expect(page.locator("footer")).toHaveCount(0);
    });

    test("puts the enqueued song in the panel and in the bar", async ({ page }) => {
        const title = await enqueueFirstSong(page);

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".play-queue__name")).toHaveText(title);
        await expect(page.locator(".player-bar__name")).toHaveText(title);
    });

    test("survives a navigation, because the queue lives in the layout", async ({ page }) => {
        await enqueueFirstSong(page);

        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".player-bar")).toBeVisible();
    });

    test("leaves a listing enough width to still be a table", async ({ page }) => {
        /*
         * The reason the DataTable's container breakpoint moved to `landscape`. At 1440px
         * the panel leaves <main> about 1170px; under the old `desktop` (1024px) line that
         * was fine, but the margin was thin enough that a 1280px laptop tipped over it and
         * every listing in the app turned into cards the moment anything was queued.
         */
        await enqueueFirstSong(page);

        await page.goto("/music/songs");
        await expect(page.locator("table")).toBeVisible();
        await expect(page.locator(".dt-cards")).toBeHidden();
    });

    test("empties back to the footer when the queue is cleared", async ({ page }) => {
        await enqueueFirstSong(page);

        await page.locator(".play-queue__clear").click();

        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".player-bar")).toHaveCount(0);
        await expect(page.locator("footer")).toBeVisible();
    });
});

test.describe("the play queue on a narrow screen", () => {
    test.use({ viewport: { width: 420, height: 850 } });

    test("becomes a bottom sheet sitting on the player bar", async ({ page }) => {
        // At this width the DataTable is in card mode and the <table> is display:none,
        // so the song is reached through a card rather than a row.
        await page.goto("/music/songs");
        await page.locator(".dt-cards a").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await page.locator(".hero-section__actions button").click();
        await expect(page.locator(".play-queue")).toBeVisible();

        const sheet = (await page.locator(".play-queue").boundingBox())!;
        const bar = (await page.locator(".player-bar").boundingBox())!;

        // Half the viewport, spanning its full width — a sheet, not a column.
        expect(sheet.width).toBeGreaterThan(400);
        expect(Math.round(sheet.height)).toBe(425);
        // Resting exactly on top of the bar, never behind it.
        expect(Math.round(sheet.y + sheet.height)).toBe(Math.round(bar.y));
    });
});
