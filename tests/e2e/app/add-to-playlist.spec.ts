import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { enqueueFromHero, openQueuePanel, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * Adding a song to a playlist from its own page, in a real engine.
 *
 * ONE THING HERE IS UNAVAILABLE TO EVERY OTHER LAYER, and it is the reason this file exists:
 * the OFFER REFRESHES ITSELF. Pressing save posts, the server answers `back()`, the page
 * re-renders, and `addablePlaylists` is recomputed — so the playlist just written to leaves the
 * select without anything in the browser having guessed at it. Each link of that chain is
 * pinned elsewhere (AddTracksToPlaylistTest for the write, AddablePlaylistsPropTest for the
 * prop, useAddToPlaylist.test.ts for the control), and that they meet is not.
 *
 * THE QUEUE'S MODAL IS HERE FOR ONE REASON ONLY: it is the single place in the app where a
 * Select sits welded to a FormRow's addon, and the seam between them is pure layout. The two
 * controls each drew their own 2px border against each other until `_addon.scss` learned to
 * name a select — a defect no unit test has a rendering engine to see, and one that reads as a
 * doubled line rather than as anything obviously broken. What the modal DOES is covered by
 * QueuePlaylistModal.test.ts, which can drive a queue directly and far more cheaply.
 *
 * TWO PLAYLISTS, NOT ONE, and that is what makes the assertion stable rather than dependent on
 * what else is in the database: after adding to the first, the select must still be there
 * offering the second. With a single playlist the whole block would collapse to its "already in
 * all of them" line, which is a different — and here accidental — state.
 *
 * ITS OWN ACCOUNT, and not because of the play queue this file never touches. Creating
 * playlists adds rows to the shared account's LISTING, and `playlists.spec.ts`'s drag test
 * computes pointer coordinates from where its own three rows sit on that page — so three extra
 * rows above them made a drag land somewhere else, and the suite failed in a file this one does
 * not go near. State a spec WRITES and another spec READS is the whole reason SPEC_USERS
 * exists; a queue was simply the first kind of it.
 *
 * EVERY TEST CREATES WHAT IT NEEDS. `reuseExistingServer` keeps a server and its data alive
 * between runs, so the names carry a per-invocation stamp; the same rule (and the same trap)
 * playlists.spec.ts records at length.
 */
test.use({ storageState: specStorageState("addToPlaylist") });

/*
 * SEQUENTIAL, IN ONE WORKER — which the account above is worthless without. `fullyParallel`
 * parallelises at the TEST level, so without this the two tests here would run concurrently
 * against one account and each would see the other's playlists in its select.
 */
test.describe.configure({ mode: "default" });

// The queue is server state and follows the account, so this file owes the same reset every
// queue-touching spec does — even though only one test here builds one.
test.beforeEach(async () => {
    await clearServerQueue("addToPlaylist");
});

// A tab flushes its queue as it closes, with `keepalive`, so that request can outlive the test
// and land after the next one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** Wide enough for a write contended by three workers on one sqlite file — see playlists.spec.ts. */
const expectSlow = expect.configure({ timeout: 15_000 });

/** Suffix making every name in this run unique, since a server may outlive the run. */
const STAMP = Date.now().toString(36);
const TARGET = `E2E Ziel ${STAMP}`;
const OTHER = `E2E Andere ${STAMP}`;

/** Create a playlist through the form, ending up back on the listing. */
const createPlaylist = async (page: Page, name: string): Promise<void> => {
    const posted = page.waitForResponse(
        response => response.url().endsWith("/playlists") && response.request().method() === "POST"
    );

    await page.goto("/playlists/create");
    await page.locator("#name").fill(name);
    await page.getByRole("button", { name: /^Wiedergabeliste anlegen$/u }).click();
    await posted;
    await page.waitForURL(/\/playlists$/u);
};

/** Open the first song in the library, whichever one the random seed produced. */
const openASong = async (page: Page): Promise<void> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
};

/**
 * The playlist names the hero's select currently offers.
 *
 * It opens the listbox and closes it again, because the panel is a `popover="manual"` promoted
 * into the TOP LAYER: nothing dismisses it on its own (not even Escape, which is what `manual`
 * means), so one left open would sit over whatever the next step wants to click.
 */
const offered = async (page: Page): Promise<string[]> => {
    const trigger = page.locator(".add-to-playlist .form-select__button");

    await trigger.click();
    const labels = await page.locator(".add-to-playlist .form-select__option-label").allTextContents();
    await trigger.click();

    return labels;
};

test.describe("adding a song to a playlist from its hero", () => {
    test("adds it, and stops offering the playlist that now has it", async ({ page }) => {
        await createPlaylist(page, TARGET);
        await createPlaylist(page, OTHER);

        await openASong(page);

        const block = page.locator(".add-to-playlist");
        await expectSlow(block).toBeVisible();
        expect(await offered(page)).toEqual(expect.arrayContaining([TARGET, OTHER]));

        // Pick the playlist by name rather than by position: the account may hold others.
        await block.locator(".form-select__button").click();
        await page.locator(".form-select__option", { hasText: TARGET }).click();

        const posted = page.waitForResponse(
            response =>
                /\/playlists\/[0-9a-f-]{36}\/tracks$/u.test(new URL(response.url()).pathname) &&
                response.request().method() === "POST"
        );
        await block.locator("button.btn").click();
        await posted;

        /*
         * THE ASSERTION THIS FILE IS FOR. The offer came back narrowed, from the server, in the
         * response to the write — nothing in the browser removed the option. The other playlist
         * is still there, which is what proves the list was recomputed rather than emptied.
         */
        await expect.poll(() => offered(page), { timeout: 15_000 }).not.toContain(TARGET);
        expect(await offered(page)).toContain(OTHER);

        // And it really landed: the listing counts one track where a new playlist has none.
        await page.goto("/playlists");
        const row = page.locator("li.playlist", { hasText: TARGET });
        await expectSlow(row).toBeVisible();
        await expectSlow(row.getByText("Titel", { exact: true })).toBeVisible();
    });

    test("keeps save disabled until a playlist is chosen", async ({ page }) => {
        // A real `disabled` attribute on a real button — the state a reader meets first, and the
        // one thing on this block that is visible before anything is pressed.
        await createPlaylist(page, `E2E Ungewählt ${STAMP}`);
        await openASong(page);

        await expectSlow(page.locator(".add-to-playlist button.btn")).toBeDisabled();
    });

    test("welds the queue modal's select to its addon as one control, not two", async ({ page }) => {
        /*
         * PURE LAYOUT, and the only place in the app that has this pair: FormRow's addon beside
         * a Select. `.form-input` gets its seam from a rule that cannot reach a Select, whose
         * field is a trigger BUTTON inside a wrapper — so both drew a full border and the join
         * was a 4px line with the button's rounded corners cutting into the addon's square edge.
         *
         * Asserted as GEOMETRY rather than as a screenshot: the numbers say exactly what was
         * wrong (two 2px borders meeting at one x, two different radii) and cannot drift with a
         * font or a theme.
         */
        await createPlaylist(page, `E2E Naht ${STAMP}`);

        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await enqueueFromHero(page);
        await openQueuePanel(page);

        await page.locator(".play-queue__header .popover-button").click();
        await page.getByRole("button", { name: /Zu Wiedergabeliste hinzufügen/u }).click();
        await expectSlow(page.locator("#queue-playlist-form")).toBeVisible();

        const seam = await page.evaluate(() => {
            const read = (selector: string) => {
                const element = document.querySelector(selector)!;
                const style = getComputedStyle(element);

                return {
                    left: Math.round(element.getBoundingClientRect().left),
                    right: Math.round(element.getBoundingClientRect().right),
                    borderLeft: style.borderLeftWidth,
                    borderRight: style.borderRightWidth,
                    radius: style.borderRadius
                };
            };

            return {
                addon: read("#queue-playlist-form .form-row__addon"),
                field: read("#queue-playlist-form .form-select__button")
            };
        });

        // They touch, and exactly ONE border is drawn where they do: the addon's right one.
        expect(seam.field.left).toBe(seam.addon.right);
        expect(seam.field.borderLeft).toBe("0px");
        expect(seam.addon.borderRight).toBe("2px");

        // And the pair rounds to ONE shape — the select's 4px corner on both ends, not the
        // input's 12px on the addon and 4px on the field.
        expect(seam.addon.radius).toBe("4px 0px 0px 4px");
        expect(seam.field.radius).toBe("0px 4px 4px 0px");
    });
});
