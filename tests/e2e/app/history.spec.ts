import { expect, test } from "@playwright/test";
import { pageHeading } from "../support/actions";

/*
 * THE LISTENING HISTORY — the journey, which is the part no other layer can run.
 *
 * The grouping, the ordering and the twenty-five-day page are the server's and are pinned by
 * `assertInertia` (tests/Feature/History/HistoryPageTest.php); the day's locale label, the
 * pips and the empty state are the page's own and are Vitest's. What is left is the thing a
 * reader actually does: find the entry in a menu that only draws it for some accounts, open a
 * day, and get from a row to the track it names.
 *
 * IT RUNS AS THE CANONICAL ACCOUNT, whose fixture carries one listen (E2ESeeder::seedPlays) —
 * so this spec proves the menu entry appears for an account that HAS listened to something,
 * where every account without a play would not draw it at all. It records no listens of its
 * own: nothing here presses play, which is also the point of the page.
 */

test.describe("the listening history", () => {
    test("is reached from the user menu, and opens a day onto what was played", async ({ page }) => {
        await page.goto("/music");

        // The menu draws this entry only for a reader with plays — the fixture gives this
        // account one, so its absence here would be the gate failing rather than the fixture.
        await page.getByRole("button", { name: /Benutzermenü öffnen/ }).click();
        const entry = page.getByRole("link", { name: "Wiedergabeverlauf" });
        await expect(entry).toBeVisible();
        await entry.click();

        await page.waitForURL("**/history");
        // NOT `pageHeading`: that helper requires a `.hero-section`, deliberately, so it only
        // answers for a DETAIL page. This is a page about the reader rather than about a thing
        // in the library, so its headline is the plain glowing one — as the audiobooks entry
        // page's is.
        await expect(page.locator("main h2").first()).toHaveText("Wiedergabeverlauf");

        // The stack opens CLOSED: the run of days is the first thing worth seeing, and
        // twenty-five open sections is a page nobody can find their way back up.
        const day = page.locator(".accordion__trigger").first();
        await expect(day).toHaveAttribute("aria-expanded", "false");
        await expect(page.locator(".history-row")).toHaveCount(0);

        await day.click();
        await expect(day).toHaveAttribute("aria-expanded", "true");
        await expect(page.locator(".history-row").first()).toBeVisible();
    });

    test("leads from a row to the track it names", async ({ page }) => {
        // The row is a link and nothing else — a history that could add to itself would be the
        // wrong page — so this is the whole of what a row does.
        await page.goto("/history");
        await page.locator(".accordion__trigger").first().click();

        const row = page.locator(".history-row").first();
        const title = await row.locator(".history-row__name").innerText();

        // ANYWHERE ON THE ROW, not the words: the name's anchor is stretched over the whole
        // row, and that promise is only testable where there is a pointer to aim with.
        await row.click({ position: { x: 5, y: 5 } });

        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(title);
    });
});
