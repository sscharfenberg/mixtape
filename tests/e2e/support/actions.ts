import { expect } from "@playwright/test";
import type { Page } from "@playwright/test";
import { SEED_USER } from "./environment";

/**
 * Fill in and submit the login form.
 *
 * Fields are addressed by their `id` rather than their label. That is not laziness about
 * accessible selectors — `getByLabel(/Passwort/)` is genuinely AMBIGUOUS here, matching
 * both the password input and the "Passwort anzeigen" reveal button beside it, and a
 * strict-mode violation in a shared helper would break every spec at once. The ids are a
 * real contract in the markup (FormRow's `for-id` wires the label to them).
 *
 * @param page     the page to drive
 * @param user     credentials; defaults to the seeded account
 * @param expectTo where a successful login should land — pass null to skip the wait when
 *                 the caller is testing a FAILING login
 */
export const signIn = async (
    page: Page,
    user: { name: string; password: string } = SEED_USER,
    expectTo: RegExp | null = /\/dashboard/u
): Promise<void> => {
    await page.goto("/login");
    await page.locator("#name").fill(user.name);
    await page.locator("#password").fill(user.password);
    await page.getByRole("button", { name: /^Anmelden$/u }).click();

    if (expectTo) await page.waitForURL(expectTo);
};

/**
 * Every row's value in the column under `header`, in render order.
 *
 * Addressed by HEADER rather than by position, which matters more than it looks: the
 * tables do not share a column order. The songs listing leads with the title, but the
 * albums listing and a genre's songs tab both lead with a cover-art cell — so a
 * `td:first-child` helper silently returns a column of empty strings there, and every
 * assertion built on it compares nothing to nothing.
 *
 * Throws with the available headers when there is no match, because the alternative is a
 * confusing empty array.
 */
export const columnValues = async (page: Page, header: string | RegExp): Promise<string[]> => {
    const headers = await page.locator("thead th").allInnerTexts();

    /*
     * Compare only the FIRST LINE. A sortable header also carries a visually-hidden
     * announcement of the current sort state, so the cell's text is really
     * "Album\nNach Album aufsteigend sortiert" — and an exact match on "Album" finds
     * nothing, in a way that reads as "the column is missing".
     */
    const labels = headers.map(text => text.split("\n")[0].trim());
    const index = labels.findIndex(label => (typeof header === "string" ? label === header : header.test(label)));

    if (index < 0) throw new Error(`No column header matching ${String(header)}. Found: ${labels.join(" | ")}`);

    return page.locator(`tbody tr td:nth-child(${index + 1})`).allInnerTexts();
};

/**
 * Lower-case and strip diacritics, mirroring how the server matches a search.
 *
 * The app searches against stored accent-folded `name_fold` columns, so "Uber" legitimately
 * returns a row that RENDERS "Über". Comparing the raw strings would report that correct
 * behaviour as a failure — and only for the seeds that happen to contain an accent, which
 * is the worst kind of intermittent.
 */
export const fold = (value: string): string =>
    value
        .toLowerCase()
        .normalize("NFD")
        .replace(/\p{Diacritic}/gu, "");

/** Parse a rendered `m:ss` / `h:mm:ss` clock back into seconds, for ordering assertions. */
export const clockToSeconds = (clock: string): number =>
    clock
        .trim()
        .split(":")
        .map(Number)
        .reduce((total, part) => total * 60 + part, 0);

/**
 * The page's own heading — the title in its hero, not the wordmark the app header carries.
 *
 * Matched by the hero's own wrapper rather than by heading LEVEL, which is what this used to
 * do (`main h1, .song h1, .genre h1`) and which broke the day the level changed: the wordmark
 * is the document's `<h1>`, so hero titles became `<h2>` (2026-08-06). The wrapper is the
 * stable fact — whatever level a page passes, the hero puts it here — and it keeps the helper
 * out of the business of knowing the outline.
 */
export const pageHeading = (page: Page) => page.locator(".hero-section__title").first();

/** Wait until the DataTable has settled on the given page number. */
export const expectOnTablePage = async (page: Page, pageNumber: number): Promise<void> => {
    await expect(page.locator(".dt-pagination__current")).toHaveText(String(pageNumber));
};

/**
 * Count the DOCUMENT requests a block of interactions causes.
 *
 * Used to prove a tab change costs no round trip. Counting `framenavigated` does not work
 * for this: that event also fires for same-document history updates, which is exactly
 * what `history.replaceState` does — so it reports a "navigation" for the very case the
 * test is trying to show is free.
 */
export const countDocumentRequests = async (page: Page, block: () => Promise<void>): Promise<number> => {
    let requests = 0;
    const listener = (request: import("@playwright/test").Request): void => {
        if (request.resourceType() === "document") requests += 1;
    };

    page.on("request", listener);
    await block();
    page.off("request", listener);

    return requests;
};
