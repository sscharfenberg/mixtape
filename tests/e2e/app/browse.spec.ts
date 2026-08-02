import { expect, test } from "@playwright/test";
import { columnValues, pageHeading } from "../support/actions";

/*
 * Getting around the library: the four listings, the detail pages they lead to, and the
 * breadcrumb trail back out.
 *
 * The point of testing navigation here rather than in a unit test is that these are real
 * Inertia visits. A page's props, its breadcrumb declaration and its rendered heading all
 * have to agree AFTER a client-side navigation — including the case that has bitten this
 * app before, where a component instance is reused for a different subject and keeps
 * something from the last one (see the CoverImage reset, and the Discography page reset).
 */

test.describe("browsing the library", () => {
    test("reaches all four listings from the music page", async ({ page }) => {
        await page.goto("/music");

        for (const path of ["/music/songs", "/music/albums", "/music/artists", "/music/genres"]) {
            await page.goto(path);
            await expect(page.locator("tbody tr").first()).toBeVisible();
        }
    });

    test("walks from the songs listing into a song and back out via the breadcrumb", async ({ page }) => {
        await page.goto("/music/songs");
        const [title] = await columnValues(page, "Titel");

        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(title);

        // The trail's last crumb is the song itself and carries no link; the one before
        // it goes back to the listing.
        await page.locator(".breadcrumb").getByRole("link", { name: /Songs/u }).click();

        await page.waitForURL(/\/music\/songs$/u);
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    test("shows the song's own facts, formatted rather than raw", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        // A clock, not a number of seconds; a locale-formatted size, not a byte count.
        // These are the client-side formatters running against real server data.
        await expect(page.getByText(/^\d{1,2}:\d{2}(:\d{2})?$/u).first()).toBeVisible();
        await expect(page.getByText(/\d+,\d{2}\s(MB|GB)/u).first()).toBeVisible();
    });

    test("opens an artist and lists their records", async ({ page }) => {
        await page.goto("/music/artists");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        const [name] = await columnValues(page, /Künstler|Name/u);

        await page.locator("tbody tr").first().click();

        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(name);
    });

    test("opens an album and lists its tracks", async ({ page }) => {
        // The albums table leads with a cover cell, so the name is not column one.
        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        const [name] = await columnValues(page, "Album");

        await page.locator("tbody tr").first().click();

        await page.waitForURL(/\/music\/albums\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(name);
    });

    test("carries the right subject from one detail page to the next", async ({ page }) => {
        /*
         * The regression this guards: Vue reuses a mounted page component across an
         * Inertia visit to the same route with different params, so anything held in
         * local state must reset. Two different artists in a row is the cheapest way to
         * catch a page that kept the first one's data.
         */
        await page.goto("/music/artists");
        const names = await columnValues(page, /Künstler|Name/u);
        const rows = page.locator("tbody tr");

        await rows.first().click();
        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(names[0]);
        const firstUrl = page.url();

        await page.goBack();
        await expect(rows.first()).toBeVisible();
        await rows.nth(1).click();

        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        expect(page.url()).not.toBe(firstUrl);
        await expect(pageHeading(page)).toHaveText(names[1]);
    });

    test("renders cover art or its placeholder, never a broken image", async ({ page }) => {
        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/albums\/[0-9a-f-]{36}/u);
        // Let the covers finish resolving — an image still in flight is not a broken one.
        await page.waitForLoadState("networkidle");

        /*
         * Only VISIBLE images count. Two legitimate reasons an <img> here can report
         * itself unloaded: covers are `loading="lazy"`, so one below the fold is never
         * fetched, and the discography renders its row and card artwork together with one
         * of them display:none — a hidden lazy image is deliberately never requested.
         * Neither is a broken picture; what would be is a VISIBLE image that failed.
         *
         * The seeded library points at cover files that do not exist, so this is also a
         * live exercise of CoverImage's 404 fallback: those images must have been swapped
         * for the placeholder rather than left broken on the page.
         */
        const brokenVisible = await page.locator("img").evaluateAll(images =>
            images.filter(node => {
                const image = node as HTMLImageElement;
                const isVisible = image.getClientRects().length > 0;

                return isVisible && (!image.complete || image.naturalWidth === 0);
            }).length
        );

        expect(brokenVisible).toBe(0);
    });

    test("reports a missing song as a 404 rather than an error page", async ({ page }) => {
        const response = await page.goto("/music/songs/11111111-1111-4111-8111-111111111111");

        expect(response?.status()).toBe(404);
    });
});
