import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { settled, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

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
 * BOTH PLAYLISTS COME FROM THE FIXTURE (E2ESeeder::seedPlaylists), which is what makes their
 * SHAPE a property of the fixture rather than of this file: the deliberately non-alphabetical
 * order, the untagged track and the three same-album-free covers are documented there, and
 * nearly every assertion below leans on one of them. Building the same shape through the
 * "add to playlist" UI would spend a page load per track restating what the seeder already
 * guarantees — and would make a fault in THAT feature surface as a failure in whichever
 * assertion here happened to run first. The REORDER specs use a third playlist of their own,
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

/*
 * THE QUEUE IS SERVER STATE, so a fresh context is not a fresh player: two tests here press
 * play, and one then counts `.play-queue__row`. It is benign today only because "play" REPLACES
 * the queue and both press it on the same seven rows — which is a property of the fixture, not
 * of the tests, and the count assertion would inherit whatever another run left behind the day
 * that changes. Both halves are needed: the reset before, and the close-without-flushing after,
 * since a tab sends its queue with `keepalive` as it goes and that request outlives the test.
 */
test.beforeEach(async () => {
    await clearServerQueue("playlistDetail");
});

test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** The fixture's playlists, by the names E2ESeeder gives them. */
const POPULATED = "Roadtrip";
const EMPTY = "Ganz frisch";
const REORDERABLE = "Umsortieren";

/** How many entries the populated playlists hold — E2ESeeder::PLAYLIST_TRACKS. */
const ENTRIES = 7;

/**
 * The fixture's entries in FILE order, which is what "sort by path" must produce.
 *
 * Written out rather than derived: E2ESeeder gives each track a numbered path
 * (`/music/006.mp3`), so file order is a real, knowable order and deliberately not the one the
 * playlist is seeded in — a sort that did nothing would otherwise pass.
 */
const order = [
    "Karma Police",
    "Fitter Happier",
    "Girls & Boys",
    "Roads",
    "There Is a Light That Never Goes Out",
    "Svefn-g-englar",
    "Avalon"
];

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
        // over the maker's own words. The fixture's playlist was changed after it was made, so
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
         * it is given, so the hero declares one — but a fan of sleeves is a FIXED size, and
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
         * width. Promising one without having it is what a reader finds by hovering: outside the
         * words there is no pointer cursor and nothing to click.
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

    test("keeps all three controls out of that overlay", async ({ page }) => {
        /*
         * The other half of a stretched link, and the one that breaks silently: the overlay is
         * positioned, so it paints above every non-positioned descendant whatever the DOM
         * order — get the rung wrong and the row navigates instead of the button firing. Only
         * a real click can tell, since the markup is identical either way.
         */
        await openFromListing(page, POPULATED);
        const url = page.url();

        /*
         * POLLED, because an Inertia visit changes the address ASYNCHRONOUSLY: read straight
         * after the click, `page.url()` is still this page whether or not the overlay swallowed
         * the press, and the assertion passes on the bug it exists to catch. `toPass` gives the
         * navigation that would be the failure every chance to happen first.
         */
        await page.locator(".playlist-tracks__handle").first().click();
        await expect(async () => expect(page.url()).toBe(url)).toPass();

        await page.locator(".playlist-tracks__play").first().click();
        await expect(page.locator(".player-bar")).toBeVisible();
        expect(page.url()).toBe(url);

        /*
         * The remove button is asked the same question WITHOUT being pressed, because pressing
         * it would delete a row the rest of this file reads. `elementFromPoint` answers what
         * would actually receive the click, which is the whole thing the rung decides — the
         * two clicks above only observe it indirectly, through what they set off.
         */
        const box = (await page.locator(".playlist-tracks__remove").first().boundingBox())!;
        const hit = await page.evaluate(
            ([x, y]) => document.elementFromPoint(x, y)?.closest(".playlist-tracks__remove") !== null,
            [box.x + box.width / 2, box.y + box.height / 2]
        );

        expect(hit).toBe(true);
    });

    test("colours the play button and leaves its destructive neighbour quiet", async ({ page }) => {
        /*
         * PLAYWRIGHT IS THE ONLY LAYER THAT CAN SEE THIS. The two buttons share a shape rule
         * and differ only in paint, so a computed style is the assertion — Vitest compiles no
         * styles at all.
         *
         * It also guards a failure mode this project has already been bitten by: both colours
         * come from `light-dark()` tokens, and a CSS pipeline that cannot express that drops
         * the whole declaration rather than erroring. The button would then inherit a
         * transparent background, the build would stay green, and the only symptom would be a
         * control that quietly stopped standing out. Hence the explicit "not transparent"
         * rather than only "not equal to each other".
         */
        await openFromListing(page, POPULATED);

        const background = (selector: string): Promise<string> =>
            page.locator(selector).first().evaluate(node => getComputedStyle(node).backgroundColor);

        const play = await background(".playlist-tracks__play");
        const remove = await background(".playlist-tracks__remove");

        expect(play).not.toBe("rgba(0, 0, 0, 0)");
        expect(play).not.toBe(remove);
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

        await expect.poll(position).toBeGreaterThan(0.1);
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

    test.describe("exporting", () => {
        /*
         * The download itself, which nothing below Playwright can prove: the modal performs no
         * request — it hands a URL to the browser — so what is asserted is that a real file
         * arrives, named after the playlist, with the bytes the options asked for.
         *
         * The BYTE-level rules (the CRLF, the `-1` for an unknown runtime, the Windows-1252
         * substitution, the prefix join) are pinned server-side in ExportPlaylistTest, where
         * they can be varied cheaply. What is left here is the wiring between the two.
         */
        test("downloads the playlist as an .m3u named after it", async ({ page }) => {
            await openFromListing(page, POPULATED);
            await page.getByRole("button", { name: /Playlist-Datei exportieren/u }).click();
            await expect(page.locator("#playlist-export-form")).toBeVisible();

            const download = page.waitForEvent("download");
            await page.getByRole("button", { name: /\.m3u herunterladen/u }).click();
            const file = await download;

            expect(file.suggestedFilename()).toBe("Roadtrip.m3u");

            const body = readFileSync((await file.path())!, "utf8");
            // One line per entry, CRLF, in the playlist's own order — and the configured prefix
            // in front of each, since nothing here changed the field.
            expect(body.split("\r\n").filter(Boolean)).toHaveLength(ENTRIES);
            expect(body.startsWith("/Volumes/media/music/")).toBe(true);
        });

        test("carries the options the reader chose into the file", async ({ page }) => {
            // The extended flavour and an emptied prefix, so the file is visibly a different
            // one — which is the whole point of the three fields.
            await openFromListing(page, POPULATED);
            await page.getByRole("button", { name: /Playlist-Datei exportieren/u }).click();

            // The LABEL, not the input: RadioButton hides the real <input> behind a styled
            // span, so it carries no accessible name and cannot be checked directly. Clicking
            // the label is what a reader does anyway.
            await page.locator('label[for="format_extended"]').click();
            await page.locator("#export-prefix").fill("");

            const download = page.waitForEvent("download");
            await page.getByRole("button", { name: /\.m3u herunterladen/u }).click();
            const body = readFileSync((await (await download).path())!, "utf8");

            expect(body.startsWith("#EXTM3U\r\n")).toBe(true);
            expect(body).toContain("#EXTINF:");
            // Relative paths: no prefix means the bare stored path.
            expect(body).not.toContain("/Volumes/");
        });

        test("leaves the page where it was", async ({ page }) => {
            // An `attachment` response navigates nowhere, which is the reason for doing the
            // download natively rather than through a blob — and worth pinning, because a
            // mis-typed header would silently replace the page with the file's text.
            await openFromListing(page, POPULATED);
            const url = page.url();

            await page.getByRole("button", { name: /Playlist-Datei exportieren/u }).click();
            const download = page.waitForEvent("download");
            await page.getByRole("button", { name: /\.m3u herunterladen/u }).click();
            await download;

            expect(page.url()).toBe(url);
            await expect(page.locator(".playlist-tracks__item")).toHaveCount(ENTRIES);
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
             *
             * TWO moves, not one, and the first lands INSIDE the destination row rather than at
             * its edge: Sortable decides where to insert from where the pointer sits within the
             * row it is over, and only ever sees positions the pointer actually visits.
             */
            const dragToTop = async (title: string): Promise<void> => {
                const index = (await rowTitles(page)).indexOf(title);
                if (index <= 0) return;

                await rows.nth(index).locator(".playlist-tracks__handle").hover();
                await page.mouse.down();

                const target = (await rows.first().boundingBox())!;
                const x = target.x + target.width / 2;
                await page.mouse.move(x, target.y + target.height * 0.6, { steps: 10 });
                await page.mouse.move(x, target.y + target.height * 0.2, { steps: 10 });
                await page.mouse.up();
            };

            const expected = [before[2], before[0], before[1], ...before.slice(3)];

            /*
             * THE GESTURE IS REPEATED, NEVER SLOWED — and the difference is not a style
             * preference, it is the only direction that works. A fixed pair of moves is a race:
             * against a renderer throttled 8x the last move is often never processed and the row
             * stops ONE PLACE SHORT, which is how this reads when it fails.
             *
             * The instinct is to give it more time, and that is measurably worse. Sortable
             * autoscrolls (`scroll: true`, `scrollSensitivity: 64`), so a pointer that LINGERS
             * near the top of the list has the list scrolled out from under it and drops the row
             * near the BOTTOM — 6 runs out of 6 with a 60ms pause between increments, where the
             * unpaused version at least usually lands. Pausing between the two moves scattered
             * results across the whole list the same way. So waiting is not available, and what
             * is left is to grab the row again, which is what a hand does when a drag falls
             * short.
             *
             * It converges on the SAME expected order from wherever a partial drag left the row,
             * including an overshoot to the bottom, because lifting one row out and putting it
             * back on top never reorders the others. And it still fails if dragging is genuinely
             * broken: every attempt has to miss, not just the first.
             */
            await expect
                .poll(
                    async () => {
                        await dragToTop(before[2]);

                        return rowTitles(page);
                    },
                    { timeout: 20_000 }
                )
                .toStrictEqual(expected);

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

        test("sorts the whole playlist by path, in the click, and keeps it", async ({ page }) => {
            /*
             * The claim the feature is built on: no round trip to wait through. The rows carry
             * their own `path`, so the new order is rendered in the frame the button was
             * pressed in — asserted BEFORE the PUT is awaited, which is what makes it a test of
             * "immediately" rather than of "eventually".
             *
             * The fixture's paths are numbered (`/music/006.mp3`), so file order is a different
             * order from the one it is seeded in, and both are known.
             */
            await openFromListing(page, REORDERABLE);

            const put = page.waitForResponse(
                response => response.url().includes("/tracks/order") && response.request().method() === "PUT"
            );
            await page.getByRole("button", { name: /Playlist sortieren/u }).click();

            // No `poll`, deliberately: this must already be true.
            const sorted = (await page.locator(".playlist-tracks__name").allTextContents()).map(t => t.trim());
            expect(sorted).toStrictEqual([...sorted].sort((a, b) => order.indexOf(a) - order.indexOf(b)));
            expect(sorted[0]).toBe("Karma Police");
            expect(sorted[1]).toBe("Fitter Happier");

            await expect(page.locator(".toast-container__item")).toBeVisible();

            await put;
            await page.reload();
            await settled(page);
            expect(await rowTitles(page)).toStrictEqual(sorted);
        });

        test("says so rather than pretending, when it is already in order", async ({ page }) => {
            // Pressed twice: the second press moves nothing, so claiming success would be a
            // message about work that did not happen.
            await openFromListing(page, REORDERABLE);
            await page.getByRole("button", { name: /Playlist sortieren/u }).click();
            await page.reload();
            await settled(page);

            const before = await rowTitles(page);
            await page.getByRole("button", { name: /Playlist sortieren/u }).click();

            await expect(page.getByText(/bereits nach Dateipfad sortiert/u)).toBeVisible();
            expect(await rowTitles(page)).toStrictEqual(before);
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
