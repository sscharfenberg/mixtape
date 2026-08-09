import { randomUUID } from "node:crypto";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { specStorageState } from "../support/environment";

/*
 * One playlist's detail page, in a real engine.
 *
 * The props are pinned server-side in tests/Feature/Playlists/PlaylistPageTest.php —
 * ownership answering 404, the reader's own order, every row a complete queue entry, the fan
 * deduped per album — the rows in PlaylistTracks.test.ts, and the renumbering in
 * ReorderPlaylistTracksTest. What is left is what none of them can see, and it is all this
 * file asserts:
 *
 *   - THE JOURNEY. The listing's row leads here, and a row's title leads on to the song.
 *   - THE PLAY BUTTON REALLY REACHES THE PLAYER, audio and all.
 *   - WHICH FACTS A WIDTH SHOWS. Pure CSS, and Vitest compiles no styles at all, so this is
 *     the only layer that can answer it.
 *   - THE DRAG, which is a stream of pointer events over elements with real geometry.
 *   - THE EMPTY STATE, which is the one a new account meets first.
 *
 * BOTH PLAYLISTS COME FROM THE FIXTURE (E2ESeeder::seedPlaylists), which is also why that
 * fixture exists: with no "add to playlist" UI there is nothing to drive, and the first
 * version of this file INSERTED its own rows straight into sqlite to get a populated page.
 * Seeding them instead makes the shape a property of the fixture — where the deliberately
 * non-alphabetical order, the untagged track and the three same-album-free covers are
 * documented — rather than of this file. The REORDER specs use a third playlist of their own,
 * because they write: sharing one with the tests that assert the reader's order would leave
 * those passing or failing on which ran first.
 *
 * IT OWNS AN ACCOUNT, for both of the reasons SPEC_USERS exists and one of its own. Pressing
 * play LEAVES A PLAY QUEUE behind, and the queue is server state that follows the user — so on
 * the shared account a spec in another worker would restore a queue this file left, and fail
 * two files away from the cause. The reason of its own: playlists are private per owner, so
 * the fixture has to belong to whoever is signed in here anyway.
 */
/*
 * SERIAL, and not for readable ordering — the suite is `fullyParallel`, which splits a FILE
 * across workers at the TEST level, and the reorder specs WRITE to a playlist the others read.
 * Left parallel, the drag test's reload raced the keyboard test's renumber and came back in an
 * order neither test had asked for: the UI showed the drag, the database showed the keystroke,
 * and the failure read as an optimistic update that had not persisted. The same reason
 * playlists.spec.ts is serial.
 */
test.describe.configure({ mode: "default" });

test.use({ storageState: specStorageState("playlistDetail") });

/** The fixture's playlists, by the names E2ESeeder gives them. */
const POPULATED = "Roadtrip";
const EMPTY = "Ganz frisch";
const REORDERABLE = "Umsortieren";

/** How many entries the populated playlists hold — E2ESeeder::PLAYLIST_TRACKS. */
const ENTRIES = 7;

/**
 * Wait out the page-to-page VIEW TRANSITION, without which raw pointer events go nowhere.
 *
 * main.ts opts every navigation into the View Transitions API, and while one is running the
 * browser paints `::view-transition-*` — a pseudo-element tree belonging to the ROOT — over
 * the whole page in the top layer. Hit testing lands on that, so `elementFromPoint` returns
 * the <html> element at every coordinate on the page, including one squarely inside a row.
 *
 * Locator actions ride this out on their own: `click()` and `hover()` retry until the element
 * actually receives pointer events. Anything driven through `page.mouse` does NOT — it fires
 * once, into the snapshot, and nothing happens. That cost an hour twice over, first as a drag
 * that silently refused to start and then as a click on a row that plainly had a link under
 * it, both presenting as broken features rather than as mis-timed input.
 *
 * Polled on the symptom rather than on `:active-view-transition`, because the symptom is the
 * precondition the callers actually need: that a coordinate on this page hits something.
 */
const settled = async (page: Page): Promise<void> => {
    await expect
        .poll(() => page.evaluate(() => document.elementFromPoint(4, 4)?.tagName ?? "NONE"))
        .not.toBe("HTML");
};

/** Open a playlist by name, the way a reader does: from the listing. */
const openFromListing = async (page: Page, name: string): Promise<void> => {
    await page.goto("/playlists");
    await page.locator("li.playlist", { hasText: name }).locator("a.playlist__link").click();
    await page.waitForURL(/\/playlists\/[0-9a-f-]{36}$/u);
    await expect(page.getByRole("heading", { level: 2, name })).toBeVisible();
    await settled(page);
};

/** The rows' titles, top to bottom. Waits for the count first — `allTextContents` does not retry. */
const rowTitles = async (page: Page, expected = ENTRIES): Promise<string[]> => {
    await expect(page.locator(".playlist-tracks__item")).toHaveCount(expected);

    return (await page.locator(".playlist-tracks__name").allTextContents()).map(text => text.trim());
};

test.describe("a playlist's detail page", () => {
    test("opens from the listing's row and lists its entries", async ({ page }) => {
        await openFromListing(page, POPULATED);

        await expect(page.locator(".playlist-tracks__item")).toHaveCount(ENTRIES);
        // The trail's parent chip is the listing this row came from, and the last crumb is the
        // playlist itself. `.breadcrumb` rather than a bare `nav`: the header carries one too,
        // and its site menu links to the listing under the same word.
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
        await openFromListing(page, POPULATED);

        expect(await rowTitles(page)).toStrictEqual([
            "Karma Police",
            "Girls & Boys",
            "Roads",
            "Fitter Happier",
            "There Is a Light That Never Goes Out",
            "Svefn-g-englar",
            "Avalon"
        ]);
    });

    test("carries the playlist's blurb and facts in the hero", async ({ page }) => {
        // The four facts the listing's row shows, so a playlist reads the same in both places,
        // over the owner's own words. The fixture's playlist was changed after it was made, so
        // all four render.
        await openFromListing(page, POPULATED);

        const hero = page.locator(".hero-section");
        await expect(hero.locator(".hero-section__description")).toHaveText("Für die lange Fahrt.");
        await expect(hero.getByText("Titel", { exact: true })).toBeVisible();
        await expect(hero.getByText("Dauer", { exact: true })).toBeVisible();
        await expect(hero.getByText("Angelegt", { exact: true })).toBeVisible();
        await expect(hero.getByText("Geändert", { exact: true })).toBeVisible();
        await expect(hero).toContainText(String(ENTRIES));
    });

    test("hugs the fan instead of reserving a cover square around it", async ({ page }) => {
        /*
         * The whitespace fix (`unframedCover`). A CoverImage at hero size fills whatever square
         * it is given, so the hero declares one — but a fan of sleeves is a FIXED 152×96, and
         * inside a 240px square it sat centred with a band of empty panel above and below.
         *
         * Measured rather than eyeballed, and against the FAN rather than a fixed number: the
         * box must be the height of what it holds, whatever that turns out to be.
         */
        await openFromListing(page, POPULATED);

        const box = (await page.locator(".hero-section__cover").boundingBox())!;
        const fan = (await page.locator(".cover-sleeves").boundingBox())!;

        expect(box.height).toBeLessThanOrEqual(fan.height + 1);
    });

    test("fans three sleeves in the hero, one per album", async ({ page }) => {
        // Three of the fixture's entries carry artwork and all three are off different albums,
        // which is what a full fan needs — a cover URL is per TRACK, so without the per-album
        // dedupe several songs off one record would fan the same picture.
        await openFromListing(page, POPULATED);

        await expect(page.locator(".cover-sleeves__sleeve")).toHaveCount(3);
    });

    test("drops the clock on the untagged track rather than claiming 0:00", async ({ page }) => {
        // "Fitter Happier" is the fixture's one track with no duration. At `full` every other
        // row shows four chips and this one shows three.
        await page.setViewportSize({ width: 1500, height: 900 });
        await openFromListing(page, POPULATED);

        const untagged = page.locator(".playlist-tracks__item", { hasText: "Fitter Happier" });
        const tagged = page.locator(".playlist-tracks__item", { hasText: "Karma Police" });

        await expect(untagged.locator(".playlist-tracks__fact:visible")).toHaveCount(3);
        await expect(tagged.locator(".playlist-tracks__fact:visible")).toHaveCount(4);
    });

    test("opens the song from the title", async ({ page }) => {
        await openFromListing(page, POPULATED);
        await page.locator(".playlist-tracks__name").first().click();

        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}$/u);
        await expect(page.getByRole("heading", { level: 2, name: "Karma Police" })).toBeVisible();
    });

    test("opens the song from anywhere else in the row, including its padding", async ({ page }) => {
        /*
         * The row lights up along its whole width, so it has to BE a target along its whole
         * width — it promised one and did not have it, which is how the owner found this:
         * outside the words there was no pointer cursor and nothing to click.
         *
         * Aimed at the row's trailing padding, a few pixels inside its edge and clear of every
         * child, so this fails if the stretched overlay is missing rather than passing on some
         * child that happens to sit under the pointer.
         */
        await openFromListing(page, POPULATED);

        // `position` on the ROW, two pixels into its padding box — left of the grip and clear
        // of every child, so this fails if the stretched overlay is missing rather than
        // passing on whatever happens to sit under the pointer. A locator click rather than
        // `page.mouse`, which also makes Playwright verify the row really receives a pointer
        // event there (its hit target is the overlay, a descendant, which satisfies the check).
        await page.locator(".playlist-tracks__item").first().click({ position: { x: 2, y: 20 } });

        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}$/u);
        await expect(page.getByRole("heading", { level: 2, name: "Karma Police" })).toBeVisible();
    });

    test("keeps the two controls out of that overlay", async ({ page }) => {
        /*
         * The other half of a stretched link, and the one that breaks silently: the overlay is
         * positioned, so it paints above every non-positioned descendant whatever the DOM
         * order — get the rung wrong and the row navigates instead of the button firing. Only
         * a real click can tell, since the markup is identical either way.
         */
        await openFromListing(page, POPULATED);
        const url = page.url();

        await page.locator(".playlist-tracks__handle").first().click();
        expect(page.url()).toBe(url);

        await page.locator(".playlist-tracks__play").first().click();
        await expect(page.locator(".player-bar")).toBeVisible();
        expect(page.url()).toBe(url);
    });

    test("says so when the playlist is empty, and still stands the hero up", async ({ page }) => {
        // The state a new account meets first. The hero must still render: its title, its menu,
        // and the fan's single placeholder where artwork would be.
        await openFromListing(page, EMPTY);

        await expect(page.locator(".cover-sleeves__sleeve")).toHaveCount(1);
        await expect(page.locator(".playlist-tracks__item")).toHaveCount(0);
        await expect(page.getByText(/Diese Wiedergabeliste ist noch leer/u)).toBeVisible();
        // Nothing has happened to it since it was made, so it claims no change — and it plays
        // for no time, so it claims no duration either. It also has no blurb.
        const hero = page.locator(".hero-section");
        await expect(hero.getByText("Geändert", { exact: true })).toHaveCount(0);
        await expect(hero.getByText("Dauer", { exact: true })).toHaveCount(0);
        await expect(hero.locator(".hero-section__description")).toHaveCount(0);
    });

    test("answers 404 for a playlist that is not the reader's", async ({ page }) => {
        // The disclosure rule, end to end: a 403 would confirm the id names a real playlist on
        // a box that is deliberately reachable from the internet. PlaylistPageTest pins the
        // status; this pins that the app really serves its error page for it.
        const response = await page.goto(`/playlists/${randomUUID()}`);

        expect(response?.status()).toBe(404);
    });

    test("plays the WHOLE playlist from the row that was pressed", async ({ page }) => {
        /*
         * The button's whole meaning, and the half Vitest cannot reach. It proves the click
         * drives usePlayerQueue; only a browser shows the consequences — the footer replaced by
         * the player bar, the queue holding all seven rather than the one that was clicked, and
         * AUDIO ACTUALLY COMING OUT.
         *
         * That last one earns its place: a seeded playlist is only worth having if its entries
         * are playable, and a fixture whose rows point at files nobody wrote would look
         * completely correct up to this line — the bar appears, the title is right, and the
         * stream 404s in silence. `seedMediaFiles` puts the one-second mp3 at every path
         * E2ESeeder claims, so this is what keeps that true.
         *
         * Read off the element rather than the UI: an <audio> without `controls` is
         * `display: none`, so visibility-based APIs do not apply to it.
         */
        await openFromListing(page, POPULATED);
        await page.locator(".playlist-tracks__item").nth(2).locator(".playlist-tracks__play").click();

        await expect(page.locator(".player-bar")).toBeVisible();
        await expect(page.locator(".player-bar")).toContainText("Roads");

        // The whole playlist is queued, not just the row — the queue's own summary counts it.
        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue__row")).toHaveCount(ENTRIES);

        const position = async (): Promise<number> =>
            page.evaluate(() => document.querySelector("audio")?.currentTime ?? 0);

        await expect.poll(position, { timeout: 5_000 }).toBeGreaterThan(0.1);
        expect(await page.evaluate(() => document.querySelector("audio")?.paused)).toBe(false);
    });

    test.describe("what a width shows", () => {
        /*
         * ONE FACT PER BREAKPOINT (owner's call), plus the artwork at `landscape`. Pure CSS, so
         * this is the only layer that can check it — Vitest stubs <style> blocks out entirely.
         *
         * `:visible` rather than counting elements: the chips are all in the DOM at every
         * width and it is `display` that changes, which is exactly the distinction a DOM-only
         * assertion cannot make.
         */
        const widths = [
            { width: 400, label: "a phone", facts: 0, art: false },
            { width: 600, label: "portrait", facts: 1, art: false },
            { width: 900, label: "landscape", facts: 2, art: true },
            { width: 1200, label: "desktop", facts: 3, art: true },
            { width: 1500, label: "full", facts: 4, art: true }
        ];

        for (const { width, label, facts, art } of widths) {
            test(`shows ${facts} chip(s) at ${label} (${width}px)`, async ({ page }) => {
                await page.setViewportSize({ width, height: 900 });
                await openFromListing(page, POPULATED);

                // The first row is fully tagged, so every chip it lacks was hidden rather than
                // dropped for want of a value.
                const row = page.locator(".playlist-tracks__item", { hasText: "Karma Police" });

                await expect(row.locator(".playlist-tracks__fact:visible")).toHaveCount(facts);
                // The WRAPPER, not the <img> inside it: the image is in the DOM at every width
                // and `display` is what changes — and at these widths it may equally be
                // CoverImage's placeholder, the fixture's covers being deliberate 404s.
                const artwork = row.locator(".playlist-tracks__art");
                if (art) await expect(artwork).toBeVisible();
                else await expect(artwork).toBeHidden();
                // The grip and the play button are there at every width — they are how the row
                // is used, not how it is described.
                await expect(row.locator(".playlist-tracks__handle")).toBeVisible();
                await expect(row.locator(".playlist-tracks__play")).toBeVisible();
            });
        }

        test("adds them in order: artist, album, runtime, year", async ({ page }) => {
            // The counts above would pass on any four chips; this pins WHICH, and the order is
            // by how much each one tells you apart from the title.
            await page.setViewportSize({ width: 900, height: 900 });
            await openFromListing(page, POPULATED);

            const row = page.locator(".playlist-tracks__item", { hasText: "Karma Police" });
            await expect(row.locator(".playlist-tracks__fact--artist")).toBeVisible();
            await expect(row.locator(".playlist-tracks__fact--album")).toBeVisible();
            await expect(row.locator(".playlist-tracks__fact--duration")).toBeHidden();
            await expect(row.locator(".playlist-tracks__fact--year")).toBeHidden();
        });
    });

    test.describe("reordering", () => {
        /*
         * Its OWN playlist, because these tests write — see the file's banner. They also assert
         * a RELATIVE change rather than a fixed order, so neither depends on the other having
         * run, or not having run.
         */
        test("moves a row to the top by dragging its grip", async ({ page }) => {
            await openFromListing(page, REORDERABLE);
            const before = await rowTitles(page);

            const rows = page.locator(".playlist-tracks__item");

            /*
             * THE GRAB IS `hover()`, NOT `mouse.move()` TO A BOUNDING BOX. A box read from
             * `boundingBox()` and replayed through `page.mouse` fired into the VIEW TRANSITION
             * still covering the page — see `settled()` — so Sortable never saw a mousedown on
             * a handle and the list simply did not move.
             *
             * `openFromListing` now waits that out, which makes raw coordinates workable here
             * again; `hover()` stays because it is the better tool regardless. It resolves and
             * scrolls to the element and re-checks that the element is what receives the press,
             * where a replayed rectangle only hopes so.
             */
            await rows.nth(2).locator(".playlist-tracks__handle").hover();
            await page.mouse.down();

            // TWO moves, not one, and the first lands INSIDE the destination row rather than at
            // its edge: Sortable decides where to insert from where the pointer sits within the
            // row it is over, and only ever sees positions the pointer actually visits.
            const target = (await rows.first().boundingBox())!;
            await page.mouse.move(target.x + target.width / 2, target.y + target.height * 0.6, { steps: 10 });
            await page.mouse.move(target.x + target.width / 2, target.y + target.height * 0.2, { steps: 10 });
            await page.mouse.up();

            const expected = [before[2], before[0], before[1], ...before.slice(3)];
            await expect.poll(() => rowTitles(page)).toStrictEqual(expected);

            // And it is the SERVER's order now, not a local splice.
            await page.reload();
            await expect.poll(() => rowTitles(page)).toStrictEqual(expected);
        });

        test("moves a row with Alt+↑/↓ after the grip is clicked, and follows it with focus", async ({ page }) => {
            // Alt+↑/↓ moves the FOCUSED row, so the grip has to be clicked first — which is what
            // its tooltip says, because otherwise hovering a row and pressing the keys does
            // nothing at all and reads as broken.
            await openFromListing(page, REORDERABLE);
            const before = await rowTitles(page);

            await page.locator(".playlist-tracks__handle").first().click();
            await page.keyboard.press("Alt+ArrowDown");

            const expected = [before[1], before[0], ...before.slice(2)];
            await expect.poll(() => rowTitles(page)).toStrictEqual(expected);

            // Focus follows the row, so a second press moves the same one again — without this
            // the moved row is re-rendered elsewhere and focus falls back to <body>.
            await expect(page.locator(".playlist-tracks__item").nth(1).locator(".playlist-tracks__handle")).toBeFocused();

            await page.reload();
            await expect.poll(() => rowTitles(page)).toStrictEqual(expected);
        });

        test("does not move at the ends, and leaves the keystroke alone", async ({ page }) => {
            await openFromListing(page, REORDERABLE);
            const before = await rowTitles(page);

            await page.locator(".playlist-tracks__handle").first().click();
            await page.keyboard.press("Alt+ArrowUp");

            expect(await rowTitles(page)).toStrictEqual(before);
        });
    });
});
