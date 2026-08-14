import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";
import { openQueuePanel, pageHeading, stopQueueSync } from "../support/actions";
import { clearBookmarks, clearServerQueue, specStorageState } from "../support/environment";

/*
 * The Audiobooks area in a real browser: the three tabs, the accordion, a book's page, and the
 * one thing only a browser can show — that playing a book and coming back lands you where you
 * left off.
 *
 * WHAT IS HERE RATHER THAN IN PHPUNIT. The grouping, the credits and the bookmark page-jump all
 * have feature tests that assert the props; these are the things props cannot prove: that the
 * accordion actually reveals a shelf, that a chapter's play button fills the player, and that
 * the bookmark survives leaving the page and coming back.
 *
 * The fixture's two books are shaped for exactly this (E2ESeeder::seedAudiobooks): "Berge des
 * Wahnsinns" is the plain five-chapter case the resume test uses, and "Necrophobia 1" is the
 * anthology — six chapters, three authors, one crediting nobody — which is what the Authors tab
 * has to place under every contributor.
 */

// Its own account and a clean queue per test, for the reason widgets.spec.ts documents at
// length: the play queue is server state, so it follows a USER rather than a browser context.
test.use({ storageState: specStorageState("audiobooks") });

test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("audiobooks");
    // A bookmark outlives the queue by design, so it needs a reset of its own — without it the
    // second test to play a chapter writes nothing, the bookmark already being where it would
    // put it, and a test waiting on that write waits until it times out.
    clearBookmarks("audiobooks");
});

test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/**
 * Wait until the audio element has actually reported a position.
 *
 * THE PLAYER BAR IS NOT EVIDENCE OF PLAYBACK: it draws from the QUEUE, so it names the loaded
 * chapter the instant it is queued, before a byte has been decoded. The bookmark, by contrast,
 * is written off `timeupdate` — no position, no write — so a test that navigated away on the
 * bar's say-so left before anything had happened. It cost two failing specs to see.
 */
const waitForPlayback = async (page: Page): Promise<void> => {
    await expect
        .poll(() => page.evaluate(() => document.querySelector("audio")?.currentTime ?? 0), { timeout: 10_000 })
        .toBeGreaterThan(0);
};

/**
 * Play a chapter and wait until its bookmark has actually been STORED.
 *
 * The write is fire-and-forget by design — a player must not stall on a bookmark — so a test
 * that navigated away as soon as the audio moved raced the request it depends on. The row did
 * land, a moment after the next page had already been rendered without it, which reads exactly
 * like the feature not working. Waiting on the response is the only honest fix; a timeout here
 * would just move the flake.
 */
/**
 * THE LAST CHAPTER, always, and it is not an arbitrary choice.
 *
 * The E2E audio fixture is ONE SECOND long while the rows claim ten minutes, so a chapter ends
 * almost immediately and the player auto-advances — which writes a new bookmark, because a new
 * chapter is stored immediately by design. Bookmarking chapter 4 and coming back to find
 * chapter 5 marked is the FEATURE working; it just cannot be asserted. Playing the last chapter
 * leaves nothing to advance to, so the bookmark stays where it was put.
 */
const LAST_CHAPTER = 4;

const playChapterAndStore = async (page: Page, row: number): Promise<void> => {
    const stored = page.waitForResponse(
        response => response.url().includes("/bookmark") && response.request().method() === "PUT"
    );

    await page.locator("tbody tr").nth(row).getByRole("button").first().click();
    await waitForPlayback(page);
    await stored;
};

test.describe("the audiobooks entry page", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/audiobooks");
        // NOT `pageHeading`: that helper requires a `.hero-section`, deliberately, so it only
        // answers for a DETAIL page — an entry page's own headline is not reachable through it
        // (actions.ts says why at length). This is the area's headline.
        await expect(page.locator("main h2").first()).toBeVisible();
    });

    test("counts the collection and opens on the books", async ({ page }) => {
        // Two books, eleven chapters, three authors, two narrators — the fixture, read back
        // through the stats card.
        const card = page.locator(".widget-stats").first();
        await expect(card).toContainText("2");

        // The Books tab is first, so its grid is what a reader meets.
        await expect(page.getByRole("link", { name: /Necrophobia 1/ })).toBeVisible();
        await expect(page.getByRole("link", { name: /Berge des Wahnsinns/ })).toBeVisible();
    });

    test("counts a book's tracks as CHAPTERS on the tile, not as songs", async ({ page }) => {
        /*
         * The cover grid is the shared `Discography`, whose default word is the one an album
         * wants — so a book advertised itself as "6 Songs" until 2026-08-14. Asserted on the
         * page rather than only in the component's own Vitest spec, because the thing that
         * actually broke is the prop reaching all three grids on this page.
         */
        const tile = page.getByRole("link", { name: /Necrophobia 1/ }).first();
        await expect(tile).toContainText(/\d+ (Kapitel|chapters?)/u);
        await expect(tile).not.toContainText(/Songs?\b/u);

        // The credit tabs draw the same grid and were the two call sites easiest to miss.
        await page.getByRole("tab", { name: /Autoren|Authors/u }).click();
        await page.getByRole("button", { name: /Brian Lumley/u }).click();
        const region = page.getByRole("region", { name: /Brian Lumley/u });
        await expect(region).toContainText(/\d+ (Kapitel|chapters?)/u);
        await expect(region).not.toContainText(/Songs?\b/u);
    });

    test("files the anthology under every author who wrote in it", async ({ page }) => {
        /*
         * The point of the credit tabs, and what the schema change bought: with a book-level
         * author column "Necrophobia 1" could belong to exactly one of its three authors.
         */
        await page.getByRole("tab", { name: /Autoren|Authors/ }).click();

        for (const author of ["H.P. Lovecraft", "Brian Lumley", "Gustav Meyrink"]) {
            const section = page.getByRole("button", { name: new RegExp(author) });
            await expect(section).toBeVisible();

            await section.click();
            await expect(page.getByRole("region", { name: new RegExp(author) })).toContainText("Necrophobia 1");

            // Closed again, so the next author opens against a known state — and this is the
            // `closeOther` behaviour the page asked for besides.
            await section.click();
        }
    });

    test("puts the open author in the URL, so it can be linked", async ({ page }) => {
        await page.getByRole("tab", { name: /Autoren|Authors/ }).click();
        await page.getByRole("button", { name: /Brian Lumley/ }).click();

        await expect(page).toHaveURL(/[?&]open=/);

        // And a reload comes back to it — the half that makes the link worth having.
        await page.reload();
        await expect(page.getByRole("region", { name: /Brian Lumley/ })).toBeVisible();
    });

    test("groups the same books by narrator too", async ({ page }) => {
        await page.getByRole("tab", { name: /Erzähler|Narrators/ }).click();

        const riedel = page.getByRole("button", { name: /Lutz Riedel/ });
        await riedel.click();
        // He reads three chapters of the anthology and none of the other book.
        await expect(page.getByRole("region", { name: /Lutz Riedel/ })).toContainText("Necrophobia 1");
    });

    test("opens a book from its tile", async ({ page }) => {
        await page.getByRole("link", { name: /Necrophobia 1/ }).first().click();

        await expect(page).toHaveURL(/\/audiobooks\/[0-9a-f-]{36}$/u);
        await expect(pageHeading(page)).toHaveText("Necrophobia 1");
    });
});

test.describe("a book's page", () => {
    test("credits every author and narrator, and each chapter its own", async ({ page }) => {
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Necrophobia 1/ }).first().click();
        await expect(pageHeading(page)).toHaveText("Necrophobia 1");

        // The hero joins all three; an album would name one.
        const hero = page.locator(".hero-section");
        await expect(hero).toContainText("Brian Lumley");
        await expect(hero).toContainText("H.P. Lovecraft");

        // And the table tells one story from the next, which is what the columns are for.
        const rows = page.locator("tbody tr");
        await expect(rows.first()).toContainText("Die Ratten im Gemäuer");
        await expect(rows.first()).toContainText("H.P. Lovecraft");
        await expect(rows.nth(1)).toContainText("Brian Lumley");
        // The uncredited afterword shows nothing rather than borrowing a name.
        await expect(rows.nth(5)).toContainText("Nachwort");
        await expect(rows.nth(5)).not.toContainText("Lovecraft");
    });

    test("offers three distinct verbs, never two ways to press play", async ({ page }) => {
        /*
         * THE BUG THIS GUARDS, and the reason a label assertion earns its place: the hero used
         * the shared `SubjectActions` for its enqueue button, which brings its own "Abspielen"
         * along with it. Beside "Weiterhören" that is two play buttons — and the plainer-looking
         * one silently restarted a book somebody was forty chapters into, which is the single
         * thing this area exists to prevent. Every other test here passed throughout.
         */
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        await page.waitForURL(/\/audiobooks\/[0-9a-f-]{36}$/u);

        const verbs = () => page.locator(".action-panel button").allInnerTexts();

        // Unstarted: play and enqueue. No second play, however it is worded.
        expect((await verbs()).map(label => label.trim())).toStrictEqual([
            "Hörbuch abspielen",
            "Warteschlange"
        ]);

        // Part-way through: resume, restart, enqueue — three verbs, each a different act.
        await playChapterAndStore(page, LAST_CHAPTER);
        await page.reload();
        expect((await verbs()).map(label => label.trim())).toStrictEqual([
            "Weiterhören",
            "Von vorn",
            "Warteschlange"
        ]);
    });

    test("pressing a chapter ROW queues the whole book from there", async ({ page }) => {
        /*
         * NOT just that one chapter, which is the distinction a book cannot afford to get
         * wrong: pressing chapter 3 has to leave chapters 4, 5 and 6 behind it, or the player
         * stops at the end of the one that was pressed.
         *
         * The press lands on the ROW rather than on a control in it — there is no play button
         * any more, the row itself is the target — so this also proves the table's
         * `rowClickable` path, which a click on the title button would sail past.
         */
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        await expect(page.locator("tbody tr")).toHaveCount(5);

        await page.locator("tbody tr").nth(2).click();

        // The player took the third chapter…
        await expect(page.locator(".player-bar")).toContainText("Drittes Kapitel");

        // …and the queue holds the whole book, not one row. Read through the PANEL rather
        // than out of storage: it is what a reader sees, and it does not depend on knowing the
        // storage key or its shape.
        await openQueuePanel(page);
        await expect(page.locator(".play-queue__row")).toHaveCount(5);
    });
});

test.describe("the keyboard", () => {
    test("reaches a chapter through its title, which a row cannot offer", async ({ page }) => {
        /*
         * THE ACCESSIBILITY HALF of making the row pressable. A <tr> takes no focus and
         * announces nothing, so if the title were plain text the chapters would be unreachable
         * without a pointer — which is why the title is a real <button> wearing the cell's own
         * text. The music tables keep their title a <Link> for the same reason; a chapter has
         * no page to link to, so it is a button instead.
         */
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        await expect(page.locator("tbody tr")).toHaveCount(5);

        const title = page.locator("tbody tr").nth(1).getByRole("button");
        // Named for what pressing it does, not just the chapter — a screen reader hears the verb.
        await expect(title).toHaveAccessibleName(/Kapitel abspielen: Zweites Kapitel/);

        await title.focus();
        await page.keyboard.press("Enter");

        await expect(page.locator(".player-bar")).toContainText("Zweites Kapitel");
    });
});

test.describe("resume", () => {
    test("comes back to the chapter that was playing", async ({ page }) => {
        /*
         * THE FEATURE THE AREA EXISTS FOR, and the only test here that could not be written
         * against props: play a chapter, go somewhere else entirely, come back, and find the
         * book pointing at where it was left rather than at chapter one.
         */
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        // WAIT BEFORE READING THE URL. An Inertia visit updates the address asynchronously, so
        // `page.url()` straight after a click is still the page being left — and "returning to
        // the book" silently became "returning to the listing", which renders no table and so
        // no bookmark. Same family as the trap `pageHeading` guards against.
        await page.waitForURL(/\/audiobooks\/[0-9a-f-]{36}$/u);
        const bookUrl = page.url();

        await playChapterAndStore(page, LAST_CHAPTER);
        await expect(page.locator(".player-bar")).toContainText("Fünftes Kapitel");

        // Leave the area completely, then come back to the book by URL — a reader returning
        // tomorrow, not an SPA state that never dropped.
        await page.goto("/music");
        await expect(page.locator("main h2").first()).toBeVisible();
        await page.goto(bookUrl);

        // The chapter that was playing is the marked one.
        // `[xlink\\:href]`, not `[href]`: Icon renders the NAMESPACED attribute, and a CSS
        // attribute selector matches on the literal name — the same trap the Vitest
        // `iconNames` helper exists to hide. The class is the readable half of the check.
        const marked = page.locator("tbody tr", { has: page.locator(".chapter-name__mark") });
        await expect(marked).toHaveCount(1);
        await expect(marked).toContainText("Fünftes Kapitel");
    });

    test("the hero's play button resumes there rather than starting again", async ({ page }) => {
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        // WAIT BEFORE READING THE URL. An Inertia visit updates the address asynchronously, so
        // `page.url()` straight after a click is still the page being left — and "returning to
        // the book" silently became "returning to the listing", which renders no table and so
        // no bookmark. Same family as the trap `pageHeading` guards against.
        await page.waitForURL(/\/audiobooks\/[0-9a-f-]{36}$/u);
        const bookUrl = page.url();

        await playChapterAndStore(page, LAST_CHAPTER);
        await expect(page.locator(".player-bar")).toContainText("Fünftes Kapitel");

        await page.goto("/music");
        await page.goto(bookUrl);

        // "Weiterhören" rather than "Hörbuch abspielen" — the label itself says which it is.
        await page.getByRole("button", { name: /Weiterhören|Resume/ }).click();

        await expect(page.locator(".player-bar")).toContainText("Fünftes Kapitel");
    });

    test("pressing the marked row carries on rather than starting the chapter again", async ({ page }) => {
        // The branch a press takes on the bookmarked row: `resume()`, which seeks to the stored
        // offset, where any other row starts its chapter at 0:00. What is assertable in a
        // browser is WHICH chapter loads — the offset itself is milliseconds into a one-second
        // fixture, and a test of that would measure the fixture rather than the feature.
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Berge des Wahnsinns/ }).first().click();
        await page.waitForURL(/\/audiobooks\/[0-9a-f-]{36}$/u);
        const bookUrl = page.url();

        await playChapterAndStore(page, LAST_CHAPTER);
        await page.goto("/music");
        await page.goto(bookUrl);

        const marked = page.locator("tbody tr.chapter-row--bookmarked");
        await expect(marked).toHaveCount(1);
        await marked.click();

        await expect(page.locator(".player-bar")).toContainText("Fünftes Kapitel");
    });

    test("a book never started offers to play rather than to resume", async ({ page }) => {
        await page.goto("/audiobooks");
        await page.getByRole("link", { name: /Necrophobia 1/ }).first().click();

        await expect(page.getByRole("button", { name: /Hörbuch abspielen|Play audiobook/ })).toBeVisible();
        await expect(page.getByRole("button", { name: /Weiterhören|Resume/ })).toHaveCount(0);
    });
});
