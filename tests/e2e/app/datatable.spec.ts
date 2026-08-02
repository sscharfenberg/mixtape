import { expect, test } from "@playwright/test";
import { clockToSeconds, columnValues, expectOnTablePage, fold, pageHeading } from "../support/actions";

/*
 * The DataTable's server-driven contract, which is the single strongest reason this app
 * has an E2E layer at all.
 *
 * Sort, search and pagination live in the URL and are resolved by the controller, so
 * every interaction below is a real round trip whose result must survive a RELOAD and a
 * BACK button. None of that is observable in happy-dom: no navigation, no history, no
 * server.
 *
 * The seeded library is randomly generated (LibrarySeeder uses factories and
 * inRandomOrder), so nothing here hard-codes a song or artist name — each test compares
 * the table against ITSELF across an interaction.
 *
 * Ordering is asserted on the DURATION column, not the title. Text ordering depends on
 * the database's collation and the app's accent-folded sort columns, which JavaScript
 * cannot reproduce; a numeric column has one correct order in both worlds. There are
 * 138 seeded tracks, comfortably more than one page, which is also why "page 1 reversed
 * equals page 1 of the reverse sort" is NOT a valid invariant here.
 */

test.describe("the songs table", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    test("puts a sort in the URL and reorders the rows", async ({ page }) => {
        const before = await columnValues(page, "Titel");

        await page.getByRole("button", { name: /Titel/u }).first().click();

        await page.waitForURL(/sort=name/u);
        expect(await columnValues(page, "Titel")).not.toEqual(before);
    });

    test("actually orders the rows by the sorted column", async ({ page }) => {
        await page.getByRole("button", { name: /Dauer/u }).first().click();
        await page.waitForURL(/sort=duration/u);

        // Untagged tracks render an empty duration cell; NaN would silently break the
        // comparison rather than fail it, so they are dropped first.
        const seconds = (await columnValues(page, "Dauer")).filter(Boolean).map(clockToSeconds);

        expect(seconds).toEqual([...seconds].sort((a, b) => a - b));
    });

    test("reverses the order on a second click", async ({ page }) => {
        const header = page.getByRole("button", { name: /Dauer/u }).first();

        await header.click();
        await page.waitForURL(/sort=duration/u);

        await header.click();
        await page.waitForURL(/dir=desc/u);

        const seconds = (await columnValues(page, "Dauer")).filter(Boolean).map(clockToSeconds);
        expect(seconds).toEqual([...seconds].sort((a, b) => b - a));
    });

    test("survives a reload, because the state is in the URL and not in memory", async ({ page }) => {
        await page.getByRole("button", { name: /Titel/u }).first().click();
        await page.waitForURL(/sort=name/u);
        const sorted = await columnValues(page, "Titel");

        await page.reload();

        await expect(page.locator("tbody tr").first()).toBeVisible();
        expect(await columnValues(page, "Titel")).toEqual(sorted);
    });

    test("filters the rows by a search term", async ({ page }) => {
        // A real word taken from the table, so the term is guaranteed to match in a
        // randomly seeded library. The longest one, because a two-letter word matches
        // half the library and makes the assertion meaningless.
        const [firstTitle] = await columnValues(page, "Titel");
        const term = firstTitle
            .split(/\s+/u)
            .map(word => word.replace(/[^\p{L}]/gu, ""))
            .sort((a, b) => b.length - a.length)[0];

        await page.getByRole("searchbox").fill(term);
        await page.waitForURL(/search=/u);

        const rows = await page.locator("tbody tr").allInnerTexts();
        expect(rows.length).toBeGreaterThan(0);
        /*
         * Matched against the WHOLE row, accent-folded on both sides. The row, because the
         * search spans title, artist, album and genre — so a row can legitimately match on
         * a column other than the one the term came from. Folded, because the server
         * matches against its `name_fold` columns, so searching "Uber" correctly returns a
         * row that renders "Über" and a naive comparison would call that a failure.
         */
        rows.forEach(row => expect(fold(row)).toContain(fold(term)));
    });

    test("says so when a search matches nothing, instead of showing an empty table", async ({ page }) => {
        await page.getByRole("searchbox").fill("zzz-kein-treffer-zzz");
        await page.waitForURL(/search=/u);

        await expect(page.getByText(/keine (Ergebnisse|Treffer)/iu).first()).toBeVisible();
        await expect(page.locator("tbody tr")).toHaveCount(0);
    });

    test("pages forward and back, changing the rows each time", async ({ page }) => {
        const firstPage = await columnValues(page, "Titel");

        await page.getByRole("button", { name: "Nächste Seite" }).click();
        await page.waitForURL(/page=2/u);
        const secondPage = await columnValues(page, "Titel");

        expect(secondPage).not.toEqual(firstPage);

        await page.getByRole("button", { name: "Vorherige Seite" }).click();
        // Wait on the pager itself: "a row is visible" is already true of the page-2 rows
        // still on screen, so it races and compares the wrong page.
        await expectOnTablePage(page, 1);
        expect(await columnValues(page, "Titel")).toEqual(firstPage);
    });

    test("keeps the sort when paging, rather than resetting it", async ({ page }) => {
        await page.getByRole("button", { name: /Dauer/u }).first().click();
        await page.waitForURL(/sort=duration/u);

        await page.getByRole("button", { name: "Nächste Seite" }).click();
        await page.waitForURL(/page=2/u);

        // Both bits of state ride in the same query string; losing one on a page turn
        // would silently reshuffle the list under the reader.
        await expect(page).toHaveURL(/sort=duration/u);
        const seconds = (await columnValues(page, "Dauer")).filter(Boolean).map(clockToSeconds);
        expect(seconds).toEqual([...seconds].sort((a, b) => a - b));
    });

    test("steps back through table state with the browser's Back button", async ({ page }) => {
        await page.getByRole("button", { name: /Titel/u }).first().click();
        await page.waitForURL(/sort=name/u);
        const sorted = await columnValues(page, "Titel");

        await page.getByRole("button", { name: "Nächste Seite" }).click();
        await page.waitForURL(/page=2/u);

        await page.goBack();

        await page.waitForURL(/sort=name/u);
        expect(await columnValues(page, "Titel")).toEqual(sorted);
    });

    test("opens a song's page from its row", async ({ page }) => {
        const [title] = await columnValues(page, "Titel");

        await page.locator("tbody tr").first().click();

        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        // Scoped past the header's wordmark <h1>, which every page carries.
        await expect(pageHeading(page)).toHaveText(title);
    });
});
