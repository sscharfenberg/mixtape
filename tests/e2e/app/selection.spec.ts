import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { dismissQueuePeek, isWrite, openQueuePanel, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * Bulk actions over a DataTable's ticked rows, in a real engine.
 *
 * WHAT ONLY THIS LAYER CAN ANSWER, and each is a whole chain rather than a link:
 *
 *   - THE SELECTION SURVIVES PAGING but not a re-sort. Both are real Inertia visits carrying
 *     `preserveState`, so the table keeps its instance and the difference between them is
 *     whether the QUESTION changed — a distinction with no navigation and no server behind it
 *     in happy-dom, and therefore unobservable at any other layer. It is also the rule most
 *     likely to break silently: a selection quietly emptied by page two reads as a lost click,
 *     and building a playlist across pages is most of what the checkboxes are for.
 *   - A CONTAINER ROW EXPANDS SERVER-SIDE. Ticking one album and adding it puts its TRACKS in
 *     the playlist — more rows than were ticked. Every link is pinned elsewhere
 *     (AddTracksToPlaylistTest for the write, SelectionActions.test.ts for the body it sends),
 *     and that the id of an album becomes a list of songs across a real round trip is not.
 *   - PLAY MEANS THE TICKED ROWS AND NOTHING ELSE. The endpoint, the queue and the player bar
 *     have to agree, over a fetch that is not an Inertia visit — which is precisely why nothing
 *     re-renders and the assertion is possible at all.
 *
 * ITS OWN ACCOUNT for two of the three reasons SPEC_USERS lists: this spec leaves a QUEUE and it
 * leaves PLAYLISTS. See that file.
 *
 * THE LIBRARY IS A FIXED FIXTURE (database/seeders/E2ESeeder.php), so the counts below are
 * facts rather than whatever the database happened to hold.
 */
test.use({ storageState: specStorageState("selection") });

/*
 * SEQUENTIAL, IN ONE WORKER. The tests here share one account, one queue and one playlist
 * listing; run concurrently they would tick rows into each other's selections and add to each
 * other's playlists.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("selection");
});

// A tab flushes its queue as it closes, with `keepalive`, so that request can outlive the test
// and land after the next one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** Suffix making every name in this run unique, since `reuseExistingServer` outlives a run. */
const STAMP = Date.now().toString(36);

/**
 * The per-row checkboxes of the table currently on screen — the LABELS, not the inputs.
 *
 * Checkbox.vue hides the native input (`opacity: 0`, zero-sized) and styles the adjacent label
 * as the box, which is the whole of the control a reader ever sees or clicks. Targeting the
 * input fails as "element is not visible", which reads as the column being missing rather than
 * as the test aiming at the wrong node.
 */
const rowChecks = (page: Page) => page.locator("tbody .dt-body__check label");

/** The bulk-action menu, which does not exist at all until something is ticked. */
const actions = (page: Page) => page.locator(".selection-actions");

/**
 * Open the bulk-action menu and hand back its entries.
 *
 * The entries are in a native `[popover]`, so they are in the DOM from the start and only
 * VISIBLE once the trigger is pressed — which is why every assertion below goes through here
 * rather than locating them directly. A locator that matched them while the panel was shut
 * would pass on a menu no reader could reach.
 */
const openSelectionMenu = async (page: Page) => {
    await actions(page).locator(".popover-button").click();

    const items = page.locator(".selection-actions .popover-list-item");
    await expect(items.first()).toBeVisible();

    return items;
};

/** Tick the first `count` rows and wait for the toolbar to catch up. */
const tickRows = async (page: Page, count: number): Promise<void> => {
    for (let index = 0; index < count; index++) {
        await rowChecks(page).nth(index).click();
    }

    await expect(page.locator(".dt-toolbar__selection")).toHaveText(`${count} ausgewählt`);
};

/** Create a playlist through the form, ending up back on the listing. */
const createPlaylist = async (page: Page, name: string): Promise<void> => {
    const posted = page.waitForResponse(
        response => response.url().endsWith("/playlists") && isWrite(response, "POST")
    );

    await page.goto("/playlists/create");
    await page.locator("#name").fill(name);
    await page.getByRole("button", { name: /^Wiedergabeliste anlegen$/u }).click();
    await posted;
    await page.waitForURL(/\/playlists$/u);
};

test.describe("the songs listing", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    test("shows nothing until a row is ticked, then offers the three verbs", async ({ page }) => {
        await expect(actions(page)).toHaveCount(0);

        await tickRows(page, 1);

        // One trigger in the toolbar, not three buttons: the strip already holds a search field,
        // a narrowing chip and the selection count, and three labelled buttons fit beside them
        // only on a very wide screen.
        await expect(actions(page).locator(".popover-button")).toHaveCount(1);
        await expect(page.locator(".selection-actions .popover-list-item").first()).toBeHidden();

        await expect(await openSelectionMenu(page)).toHaveText([
            "Auswahl abspielen",
            "Auswahl anhängen",
            "Zur Wiedergabeliste"
        ]);
    });

    test("plays exactly the ticked rows, and drops the ticks once it has", async ({ page }) => {
        const titles = await page.locator("tbody tr .songs__title").allTextContents();

        const resolved = page.waitForResponse(
            response => response.url().endsWith("/queue/tracks") && isWrite(response, "POST")
        );
        await tickRows(page, 2);
        const items = await openSelectionMenu(page);
        await items.filter({ hasText: "Auswahl abspielen" }).click();
        await resolved;

        // Growing the queue reveals the panel for three seconds and then hides it again, so the
        // peek has to be cancelled before the panel is opened deliberately — otherwise
        // `openQueuePanel` sees it, skips its own toggle, and waits for a clip-path on an
        // element that is vanishing. It fails only under load, where the wait outlives the peek.
        await dismissQueuePeek(page);
        await openQueuePanel(page);

        // The queue holds the two ticked songs and nothing else — the endpoint answered with
        // exactly what was ticked, and `playNow` replaced rather than appended.
        await expect(page.locator(".play-queue__row")).toHaveCount(2);

        /*
         * AS A SET, because the queue's order is the PLAYER'S, not the table's: QueuePayload
         * sorts album-then-disc-then-track whatever the subject and whatever the listing was
         * sorted by, so two songs ticked in a title-sorted table arrive as records. That is the
         * documented rule rather than an accident here — asserting the table's order would pin
         * the opposite of what the app promises.
         */
        const queued = await page.locator(".play-queue__name").allTextContents();
        expect(queued.slice().sort()).toEqual(titles.slice(0, 2).sort());

        // …and the menu is gone with the ticks it acted on.
        await expect(actions(page)).toHaveCount(0);
        await expect(page.locator(".dt-toolbar__selection")).toHaveCount(0);
    });

    test("keeps the selection across a page change but drops it on a re-sort", async ({ page }) => {
        // Two navigations, one rule each, and the difference between them is the whole point:
        // page two is the same question further down, while a re-sort is a different question —
        // so the rows a reader ticked may not even be on screen any more.
        await tickRows(page, 1);

        await page.getByRole("button", { name: "Nächste Seite" }).click();
        await page.waitForURL(/[?&]page=2/u);

        await expect(page.locator(".dt-toolbar__selection")).toHaveText("1 ausgewählt");

        await page.getByRole("button", { name: /Titel/u }).first().click();
        await page.waitForURL(/[?&]sort=name/u);

        await expect(page.locator(".dt-toolbar__selection")).toHaveCount(0);
        await expect(actions(page)).toHaveCount(0);
    });
});

test.describe("the albums listing", () => {
    test("adds a whole album's TRACKS from one ticked row", async ({ page }) => {
        const target = `E2E Auswahl ${STAMP}`;
        await createPlaylist(page, target);

        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await tickRows(page, 1);
        const items = await openSelectionMenu(page);
        await items.filter({ hasText: "Zur Wiedergabeliste" }).click();

        const dialog = page.locator("#add-to-playlist-form");
        await expect(dialog).toBeVisible();

        // By name rather than by position: the account may hold playlists from an earlier run.
        await dialog.locator(".form-select__button").click();
        await page.locator(".form-select__option", { hasText: target }).click();

        const posted = page.waitForResponse(
            response =>
                /\/playlists\/[0-9a-f-]{36}\/tracks$/u.test(new URL(response.url()).pathname) &&
                isWrite(response, "POST")
        );
        await page.getByRole("button", { name: /^Hinzufügen$/u }).click();
        await posted;

        /*
         * THE ASSERTION THIS TEST IS FOR. One row was ticked and the playlist holds several
         * songs: the album's id travelled as an id and came back expanded, which no layer below
         * this one sees end to end.
         */
        await page.goto("/playlists");
        await page.locator("li.playlist", { hasText: target }).locator(".playlist__link").click();
        await page.waitForURL(/\/playlists\/[0-9a-f-]{36}/u);

        await expect(page.locator(".playlist-tracks__item").first()).toBeVisible();
        expect(await page.locator(".playlist-tracks__item").count()).toBeGreaterThan(1);
    });
});
