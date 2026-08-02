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
 * The library is a FIXED fixture (database/seeders/E2ESeeder.php) — real albums with fixed
 * names, durations and timestamps — so these specs can name a song and assert an exact
 * result rather than comparing the page against itself.
 *
 * Ordering is still asserted on the DURATION column rather than the title, and that is not
 * about determinism: text ordering depends on the database's collation and the app's
 * accent-folded sort columns, which JavaScript's localeCompare does not reproduce, so
 * comparing titles would assert Node's collation instead of the app's. The seeder gives
 * every track a unique duration precisely so that ordering is total — with ties there
 * would be two correct orderings and an equality assertion would pick one arbitrarily.
 */

/** Fixture facts these specs rely on. See database/seeders/E2ESeeder.php. */
const LIBRARY = {
    /** Total music tracks — more than one page at the default size of 50. */
    tracks: 67,
    /** A title that appears exactly once, for an unambiguous search. */
    uniqueTitle: "Paranoid Android",
    /** The one track seeded with no duration, composer or publisher. */
    untaggedTitle: "Fitter Happier"
} as const;

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

    test("narrows to the one row a unique term matches", async ({ page }) => {
        await page.getByRole("searchbox").fill("Paranoid");
        await page.waitForURL(/search=/u);

        expect(await columnValues(page, "Titel")).toStrictEqual([LIBRARY.uniqueTitle]);
    });

    test("searches across the other columns, not just the title", async ({ page }) => {
        // "Portishead" is an artist, not a song — every one of its tracks must come back.
        await page.getByRole("searchbox").fill("Portishead");
        await page.waitForURL(/search=/u);

        const rows = await page.locator("tbody tr").allInnerTexts();
        expect(rows.length).toBeGreaterThan(1);
        rows.forEach(row => expect(row).toContain("Portishead"));
    });

    test("matches through accents, because the server searches a folded column", async ({ page }) => {
        /*
         * The reason `name_fold` exists. Typing plain "Ros" has to find "Sigur Rós" —
         * a reader is not going to reach for the accent, and on a German keyboard may not
         * be able to. Asserted with the accent present in the rendered row, since that is
         * the thing being folded PAST rather than away.
         */
        await page.getByRole("searchbox").fill("Sigur Ros");
        await page.waitForURL(/search=/u);

        const rows = await page.locator("tbody tr").allInnerTexts();
        expect(rows.length).toBeGreaterThan(0);
        rows.forEach(row => expect(fold(row)).toContain("sigur ros"));
        expect(rows.join(" ")).toContain("Sigur Rós");
    });

    test("says so when a search matches nothing, instead of showing an empty table", async ({ page }) => {
        await page.getByRole("searchbox").fill("zzz-kein-treffer-zzz");
        await page.waitForURL(/search=/u);

        await expect(page.getByText(/keine (Ergebnisse|Treffer)/iu).first()).toBeVisible();
        await expect(page.locator("tbody tr")).toHaveCount(0);
    });

    test("reports the whole library's size, not just the page on screen", async ({ page }) => {
        // DataTableService::DEFAULT_PAGE_SIZE is 50 (the pager offers 25/50/100).
        await expect(page.locator(".dt-pagination__info")).toHaveText(`1–50 / ${LIBRARY.tracks}`);
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
