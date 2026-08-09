import { randomUUID } from "node:crypto";
import { expect, test } from "@playwright/test";
import { specStorageState } from "../support/environment";

/*
 * One playlist's detail page, in a real engine.
 *
 * The props are pinned server-side in tests/Feature/Playlists/PlaylistPageTest.php —
 * ownership answering 404, the reader's own order, every row a complete queue entry, the fan
 * deduped per album — and the rows themselves in PlaylistTracks.test.ts, including which of
 * the two buttons replaces the queue and which appends to it. What is left is what neither
 * can see, and it is all this file asserts:
 *
 *   - THE JOURNEY. The listing's row leads here, and the title of a row leads on to the song.
 *     Each page is proven separately; that they meet is not.
 *   - THE ROW'S PLAY BUTTON REALLY REACHES THE PLAYER. Vitest proves it calls the queue; only
 *     a browser shows the footer replaced by the player bar as a result.
 *   - THE EMPTY STATE, which is the one a new account meets first — a playlist starts empty
 *     and nothing in the UI adds a track to one yet.
 *
 * BOTH PLAYLISTS COME FROM THE FIXTURE (E2ESeeder::seedPlaylists), which is also why that
 * fixture exists: with no "add to playlist" UI there is nothing to drive, and the first
 * version of this file INSERTED its own rows straight into sqlite to get a populated page.
 * Seeding them instead makes the shape a property of the fixture — where the deliberately
 * non-alphabetical order, the untagged track and the three same-album-free covers are
 * documented — rather than of this file.
 *
 * IT OWNS AN ACCOUNT, for both of the reasons SPEC_USERS exists and one of its own. Pressing
 * a row's play button LEAVES A PLAY QUEUE behind, and the queue is server state that follows
 * the user — so on the shared account a spec in another worker would restore a queue this
 * file left, and fail two files away from the cause. The reason of its own: playlists are
 * private per owner, so the fixture has to belong to whoever is signed in here anyway.
 */
test.use({ storageState: specStorageState("playlistDetail") });

/** The fixture's two playlists, by the names E2ESeeder gives them. */
const POPULATED = "Roadtrip";
const EMPTY = "Ganz frisch";

/** How many entries `POPULATED` holds — E2ESeeder::PLAYLIST_TRACKS. */
const ENTRIES = 7;

/** Open a playlist by name, the way a reader does: from the listing. */
const openFromListing = async (page: import("@playwright/test").Page, name: string): Promise<void> => {
    await page.goto("/playlists");
    await page.locator("li.playlist", { hasText: name }).locator("a.playlist__link").click();
    await page.waitForURL(/\/playlists\/[0-9a-f-]{36}$/u);
};

test.describe("a playlist's detail page", () => {
    test("opens from the listing's row and lists its entries", async ({ page }) => {
        await openFromListing(page, POPULATED);

        await expect(page.getByRole("heading", { level: 2, name: POPULATED })).toBeVisible();
        await expect(page.locator(".playlist-tracks__item")).toHaveCount(ENTRIES);
        // The trail's parent chip is the listing this row came from, and the last crumb is
        // the playlist itself. `.breadcrumb` rather than a bare `nav`: the header carries one
        // too, and its site menu links to the listing under the same word.
        const trail = page.locator(".breadcrumb");
        await expect(trail.getByRole("link", { name: /Wiedergabelisten/u })).toBeVisible();
        await expect(trail.locator("[aria-current='page']")).toHaveText(POPULATED);
    });

    test("renders the entries in the reader's own order, not the library's", async ({ page }) => {
        /*
         * The fixture's order is deliberately neither alphabetical nor the order the library
         * lists these tracks in, so a page that quietly sorted by title could not pass. The
         * expectation is the fixture's own list — reproducing a sort here would be asserting
         * JavaScript's collation rather than the app's, the trap this suite's gotchas warn
         * about for the DataTable.
         */
        await page.goto("/playlists");
        await openFromListing(page, POPULATED);

        await expect(page.locator(".playlist-tracks__item")).toHaveCount(ENTRIES);
        expect((await page.locator(".playlist-tracks__name").allTextContents()).map(text => text.trim())).toStrictEqual([
            "Karma Police",
            "Girls & Boys",
            "Roads",
            "Fitter Happier",
            "There Is a Light That Never Goes Out",
            "Svefn-g-englar",
            "Avalon"
        ]);
    });

    test("fans three sleeves in the hero, one per album", async ({ page }) => {
        // Three of the fixture's entries carry artwork and all three are off different
        // albums, which is what a full fan needs — a cover URL is per TRACK, so without the
        // per-album dedupe several songs off one record would fan the same picture.
        await openFromListing(page, POPULATED);

        await expect(page.locator(".cover-sleeves__sleeve")).toHaveCount(3);
    });

    test("drops the clock on the untagged track rather than claiming 0:00", async ({ page }) => {
        // "Fitter Happier" is the fixture's one track with no duration. Its row keeps its
        // artist, album and year chips and simply has one fewer.
        await openFromListing(page, POPULATED);

        const untagged = page.locator(".playlist-tracks__item", { hasText: "Fitter Happier" });
        const tagged = page.locator(".playlist-tracks__item", { hasText: "Karma Police" });

        await expect(untagged.locator(".playlist-tracks__fact")).toHaveCount(3);
        await expect(tagged.locator(".playlist-tracks__fact")).toHaveCount(4);
        await expect(untagged).not.toContainText(":");
    });

    test("carries the playlist's own facts in the hero", async ({ page }) => {
        // The four the listing's row shows, so a playlist reads the same in both places. The
        // fixture's playlist was changed after it was made, so all four render.
        await openFromListing(page, POPULATED);

        const hero = page.locator(".hero-section");
        await expect(hero.getByText("Titel", { exact: true })).toBeVisible();
        await expect(hero.getByText("Dauer", { exact: true })).toBeVisible();
        await expect(hero.getByText("Angelegt", { exact: true })).toBeVisible();
        await expect(hero.getByText("Geändert", { exact: true })).toBeVisible();
        await expect(hero).toContainText(String(ENTRIES));
    });

    test("opens the song a row's title links to", async ({ page }) => {
        // The row itself cannot be the link — it holds two buttons, and an <a> may not
        // contain interactive content — so the title is the only thing that navigates.
        await openFromListing(page, POPULATED);
        await page.locator(".playlist-tracks__name").first().click();

        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}$/u);
        await expect(page.getByRole("heading", { level: 2, name: "Karma Police" })).toBeVisible();
    });

    test("plays a row — for real, not just into the player bar", async ({ page }) => {
        /*
         * The half Vitest cannot reach: it proves the button drives usePlayerQueue, and only a
         * browser shows the consequence — the footer replaced by the player bar, loaded with
         * the track whose row was pressed.
         *
         * AND THEN THAT AUDIO ACTUALLY COMES OUT, which is the assertion that earns its place
         * here. A seeded playlist is only worth having if its entries are playable, and a
         * fixture whose rows point at files nobody wrote would look completely correct up to
         * this line: the bar appears, the title is right, and the stream 404s in silence.
         * `seedMediaFiles` puts the one-second mp3 at every path E2ESeeder claims, so the
         * stream route really streams — this is what keeps that true.
         *
         * Read off the element rather than the UI: an <audio> without `controls` is
         * `display: none`, so visibility-based APIs do not apply to it.
         */
        await openFromListing(page, POPULATED);
        await page.locator(".playlist-tracks__item").first().locator(".playlist-tracks__control").first().click();

        await expect(page.locator(".player-bar")).toBeVisible();
        await expect(page.locator(".player-bar")).toContainText("Karma Police");

        const position = async (): Promise<number> =>
            page.evaluate(() => document.querySelector("audio")?.currentTime ?? 0);

        await expect.poll(position, { timeout: 5_000 }).toBeGreaterThan(0.1);
        expect(await page.evaluate(() => document.querySelector("audio")?.paused)).toBe(false);
    });

    test("says so when the playlist is empty, and still stands the hero up", async ({ page }) => {
        // The state a new account meets first. The hero must still render: its title, its
        // menu, and the fan's single placeholder where artwork would be.
        await openFromListing(page, EMPTY);

        await expect(page.getByRole("heading", { level: 2, name: EMPTY })).toBeVisible();
        await expect(page.locator(".cover-sleeves__sleeve")).toHaveCount(1);
        await expect(page.locator(".playlist-tracks__item")).toHaveCount(0);
        await expect(page.getByText(/Diese Wiedergabeliste ist noch leer/u)).toBeVisible();
        // Nothing has happened to it since it was made, so it claims no change — and it plays
        // for no time, so it claims no duration either.
        const hero = page.locator(".hero-section");
        await expect(hero.getByText("Geändert", { exact: true })).toHaveCount(0);
        await expect(hero.getByText("Dauer", { exact: true })).toHaveCount(0);
    });

    test("answers 404 for a playlist that is not the reader's", async ({ page }) => {
        // The disclosure rule, end to end: a 403 would confirm the id names a real playlist
        // on a box that is deliberately reachable from the internet. PlaylistPageTest pins the
        // status; this pins that the app really serves its error page for it.
        const response = await page.goto(`/playlists/${randomUUID()}`);

        expect(response?.status()).toBe(404);
    });
});
