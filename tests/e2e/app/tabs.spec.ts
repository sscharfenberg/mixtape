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

    test("shows an artist card per artist, with a fan of covers and its numbers", async ({ page }) => {
        /*
         * The fan degrades honestly, so a one-album artist must show ONE sleeve rather than a
         * padded stack of three. Asserted as a range against the cards actually rendered
         * rather than a fixed number, because which artists a genre holds is the seeder's
         * business — what this pins is that no card is ever empty or over-fanned.
         */
        await openFirstGenre(page);
        await page.getByRole("tab", { name: /Künstler/u }).click();
        await expect(page).toHaveURL(/tab=artists/u);

        const cards = page.locator(".genre-artists__item");
        await expect(cards.first()).toBeVisible();

        for (const card of await cards.all()) {
            const sleeves = await card.locator(".genre-artists__sleeve").count();
            expect(sleeves).toBeGreaterThan(0);
            expect(sleeves).toBeLessThanOrEqual(3);
            // Three chips — songs, albums, playing time — under a non-empty name.
            await expect(card.locator(".genre-artists__fact")).toHaveCount(3);
            await expect(card.locator(".genre-artists__name")).not.toBeEmpty();
        }
    });

    test("wraps a long collaboration credit instead of clipping it", async ({ page }) => {
        /*
         * Guards a bug that is invisible to a text assertion: CSS truncation does not change
         * the DOM, so `toHaveText` still passes on a name rendered as "Jóhann Jóhannsson, Hil…".
         *
         * The LINE COUNT is what actually catches it — verified by putting the old
         * `white-space: nowrap` back and watching this fail. The clipping check below does
         * not, and that is worth knowing rather than assuming: with `text-overflow: ellipsis`
         * the box is exactly as wide as its clipped content, so `scrollWidth` equals
         * `clientWidth` and nothing looks wrong. It is kept for the OTHER failure — a name
         * that overflows with no ellipsis at all — but it is not the one doing the work here.
         *
         * The fixture carries one deliberately long collaboration credit for this.
         */
        await page.goto("/music/genres");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        await page.getByRole("searchbox").fill("Modern Classical");
        await page.waitForURL(/search=/u);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/genres\/[0-9a-f-]{36}/u);
        await page.getByRole("tab", { name: /Künstler/u }).click();

        const name = page.locator(".genre-artists__name").first();
        await expect(name).toBeVisible();

        const geometry = await name.evaluate(node => ({
            text: (node as HTMLElement).innerText,
            clipped: node.scrollWidth > node.clientWidth + 1,
            lines: Math.round(node.getBoundingClientRect().height / parseFloat(getComputedStyle(node).lineHeight))
        }));

        // The whole credit is there, it is not cut off, and it really did wrap.
        expect(geometry.text).toContain("The Cinema Orchestra");
        expect(geometry.clipped).toBe(false);
        expect(geometry.lines).toBeGreaterThan(1);

        // ...and it did not widen its card: every card in the grid is the same width.
        const widths = await page
            .locator(".genre-artists__item")
            .evaluateAll(items => items.map(item => Math.round(item.getBoundingClientRect().width)));
        expect(new Set(widths).size).toBe(1);
    });

    test("opens an artist from their card", async ({ page }) => {
        await openFirstGenre(page);
        await page.getByRole("tab", { name: /Künstler/u }).click();
        await expect(page).toHaveURL(/tab=artists/u);

        const name = await page.locator(".genre-artists__name").first().innerText();
        await page.locator(".genre-artists__link").first().click();

        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(page.locator(".hero-section__title").first()).toHaveText(name);
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
