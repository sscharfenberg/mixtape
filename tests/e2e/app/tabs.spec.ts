import { expect, test } from "@playwright/test";
import { columnValues, countDocumentRequests } from "../support/actions";

/*
 * The tabbed detail pages, and the promise useTabParam makes about them.
 *
 * That composable rewrites the URL with `history.replaceState` rather than making an
 * Inertia visit, for two documented reasons: a tabbed page already sends every panel, and
 * a visit would raise DataTable's loading overlay over content that is already on screen.
 * The unit tests prove no router call happens — but only a real browser can show that the
 * URL genuinely changed, that a RELOAD reopens the same tab, and that `replace` (not
 * `push`) means Back leaves the page instead of stepping through tabs.
 *
 * That last one is the whole design trade, and it is invisible to every other test layer.
 */

/** Open the first genre's detail page. */
const openFirstGenre = async (page: import("@playwright/test").Page): Promise<void> => {
    await page.goto("/music/genres");
    await expect(page.locator("tbody tr").first()).toBeVisible();
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/genres\/[0-9a-f-]{36}/u);
};

test.describe("a genre's tabs", () => {
    test("opens on the albums tab with no tab in the URL", async ({ page }) => {
        await openFirstGenre(page);

        expect(new URL(page.url()).searchParams.get("tab")).toBeNull();
        await expect(page.getByRole("tab", { name: /Alben/u })).toHaveAttribute("aria-selected", "true");
    });

    test("writes the chosen tab into the URL", async ({ page }) => {
        await openFirstGenre(page);

        await page.getByRole("tab", { name: /Künstler/u }).click();

        await expect(page).toHaveURL(/[?&]tab=artists/u);
        await expect(page.getByRole("tab", { name: /Künstler/u })).toHaveAttribute("aria-selected", "true");
    });

    test("reopens the same tab after a reload", async ({ page }) => {
        await openFirstGenre(page);
        await page.getByRole("tab", { name: /Songs/u }).click();
        await expect(page).toHaveURL(/[?&]tab=songs/u);

        await page.reload();

        // The point of putting it in the URL: a reload, a bookmark or a shared link all
        // land on the tab the reader was on.
        await expect(page.getByRole("tab", { name: /Songs/u })).toHaveAttribute("aria-selected", "true");
    });

    test("changes tabs without a page load, since every panel was already sent", async ({ page }) => {
        await openFirstGenre(page);

        /*
         * Counting DOCUMENT REQUESTS, not `framenavigated`. That event also fires for
         * same-document history updates — which is exactly what history.replaceState
         * does — so it would report a navigation for the very case being shown as free.
         * What actually matters is that nothing went to the server.
         */
        const requests = await countDocumentRequests(page, async () => {
            await page.getByRole("tab", { name: /Künstler/u }).click();
            await expect(page).toHaveURL(/tab=artists/u);
            await page.getByRole("tab", { name: /Songs/u }).click();
            await expect(page).toHaveURL(/tab=songs/u);
        });

        expect(requests).toBe(0);
    });

    test("leaves the page on Back rather than stepping through the tabs", async ({ page }) => {
        // `replace`, not `push`: flipping between tabs five times must not put five
        // entries between the reader and where they came from.
        await page.goto("/music/genres");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/genres\/[0-9a-f-]{36}/u);

        await page.getByRole("tab", { name: /Künstler/u }).click();
        await expect(page).toHaveURL(/tab=artists/u);
        await page.getByRole("tab", { name: /Songs/u }).click();
        await expect(page).toHaveURL(/tab=songs/u);

        await page.goBack();

        await page.waitForURL(/\/music\/genres$/u);
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    test("keeps the songs table's own state inside the tab", async ({ page }) => {
        await openFirstGenre(page);
        await page.getByRole("tab", { name: /Songs/u }).click();
        await expect(page).toHaveURL(/tab=songs/u);

        const before = await columnValues(page, "Titel");
        await page.getByRole("button", { name: /Titel/u }).first().click();
        await page.waitForURL(/sort=name/u);

        // The table's visit carries the tab through, so sorting inside a tab does not
        // throw the reader back to the first one.
        await expect(page).toHaveURL(/tab=songs/u);
        await expect(page.getByRole("tab", { name: /Songs/u })).toHaveAttribute("aria-selected", "true");
        expect(await columnValues(page, "Titel")).not.toEqual(before);
    });
});
