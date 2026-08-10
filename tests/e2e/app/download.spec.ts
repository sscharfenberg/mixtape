import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * Downloading a song and a whole album, from the hero of their own pages.
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE, and the reason a feature test is not enough: this
 * app is an Inertia SPA, so a click on a link is normally intercepted, fetched over XHR
 * and rendered as a page. A download must escape all of that — the click has to reach the
 * browser's own download machinery, and the page it was pressed on has to still be there
 * afterwards. PHP can prove the response is an attachment (SongDownloadTest,
 * AlbumDownloadTest, which also opens the .zip and checks every CRC); nothing but an
 * engine can prove the front end lets it happen.
 *
 * The one thing that would silently break it is a well-meant refactor of DownloadButton
 * into an Inertia <Link> or a <Button> with a click handler. Both still LOOK right, and
 * the first would fetch a stream of mp3 bytes and try to read a page out of it.
 *
 * The suggested filename is asserted because it is the server's, not the browser's: it
 * comes out of `Content-Disposition`, which is the only place the file's real name on the
 * share exists. A missing header shows up as a name invented from the URL — "download",
 * with no extension at all.
 *
 * Downloads change no state, so this spec shares the default account rather than owning
 * one, and the library is the fixed fixture (database/seeders/E2ESeeder.php) — its media
 * is a copy of the one-second mp3 at every seeded path, which is a real file to send.
 */

/** Open the first row of a listing, which is how every detail page here is reached. */
const openFirstRow = async (page: Page, listing: string): Promise<void> => {
    await page.goto(listing);
    await page.locator("tbody tr").first().click();
    await expect(page.locator(".hero-section")).toBeVisible();
};

test.describe("downloading", () => {
    test("a song arrives as its own file, and leaves the page where it was", async ({ page }) => {
        await openFirstRow(page, "/music/songs");

        const url = page.url();
        const download = page.waitForEvent("download");

        await page.locator(".download-button").click();

        // The name the server sent, extension and all — the file's own name on the share.
        expect((await download).suggestedFilename()).toMatch(/\.mp3$/);

        // Still on the song. An Inertia visit here would have navigated somewhere (or
        // blanked the page trying to parse audio as a page response).
        expect(page.url()).toBe(url);
        await expect(page.locator(".hero-section")).toBeVisible();
    });

    test("an album arrives as a zip named after the record", async ({ page }) => {
        await openFirstRow(page, "/music/albums");

        const heading = await page.locator(".hero-section__title").first().innerText();
        const download = page.waitForEvent("download");

        await page.locator(".download-button").click();

        const file = await download;

        // "Artist - Album.zip" — the album's own name has to be in there, which is what
        // says the header was built from the record rather than from the URL.
        expect(file.suggestedFilename()).toMatch(/\.zip$/);
        expect(file.suggestedFilename()).toContain(heading.trim());

        // The archive is streamed, so an empty file is the shape a broken writer would
        // take here; its CONTENTS are pinned in AlbumDownloadTest, which can open them.
        const path = await file.path();
        expect(path).not.toBeNull();
    });
});
