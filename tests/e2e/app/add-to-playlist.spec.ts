import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { specStorageState } from "../support/environment";

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
 * The rest of the feature is deliberately NOT re-tested here. The queue's own modal is covered
 * by QueuePlaylistModal.test.ts, which can drive a queue directly; giving it a browser would
 * mean an account of its own (a queue follows the USER — see queue.spec.ts) for a journey whose
 * only unproven half is the same round trip this file already walks.
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
});
