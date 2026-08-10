import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * The Now Playing page in a real engine: four rows over the live queue.
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE, and it is the whole reason this file exists:
 *
 *   - THE TRIO IS REAL AND IT MOVES. `previousTrack` / `nextTrack` are pinned in
 *     usePlayerQueue's own spec against a synthetic queue; that they name the tracks either side
 *     of what is actually loaded, and re-name them when playback steps, is a fact about the page
 *     wired to the player.
 *   - THE GENRE ROUND TRIP. The queue carries no genre, so the page fetches one for each of the
 *     three ids it draws and re-fetches when they change. Nothing below the server can prove that
 *     the fetch fires, lands, and lands for the RIGHT three tracks.
 *   - THE ANALYSER IS WIRED. `createMediaElementSource` needs a real element and a real
 *     AudioContext; happy-dom has neither.
 *
 * WHAT IT DELIBERATELY DOES NOT ASSERT: that the bars MOVE. Playwright launches Chromium muted,
 * so the analyser reads zeros however loudly a file is playing — measured 2026-08-09, with the
 * audio clock advancing normally and every bar on its 2% baseline. A test that waited for a bar
 * to rise would hang forever for a reason that has nothing to do with this app. The gradient was
 * checked by forcing heights from a stylesheet and looking at it.
 *
 * ITS OWN ACCOUNT, because it leaves a queue behind — the rule every queue-touching spec follows
 * (docs/testing.md → End-to-end).
 */
test.use({ storageState: specStorageState("nowPlaying") });
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("nowPlaying");
});

test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** Queue a whole artist — ten tracks, so there is a real neighbour in both directions. */
const queueAnArtist = async (page: Page): Promise<void> => {
    await page.goto("/music/artists");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
    await page.locator(".hero-section__menu .popover-button").click();
    // The second item is "enqueue"; the first replaces the queue and starts playing.
    await page.locator(".hero-section__menu .popover-list-item").nth(1).click();
    await expect(page.locator(".play-queue__row").first()).toBeVisible();
};

/** The text of one of the two neighbour cards. */
const neighbour = (page: Page, direction: "previous" | "next") =>
    page.locator(`.neighbour--${direction}`);

test.describe("the Now Playing page", () => {
    test("names what is playing and what sits either side of it", async ({ page }) => {
        await queueAnArtist(page);
        await page.goto("/now-playing");

        const playing = await page.locator(".hero-section h2").textContent();
        expect(playing).toBeTruthy();

        // At the head of a fresh queue there is nothing behind, and that is drawn rather than
        // dropped — a card that vanished would move the queue below it as playback advances.
        await expect(neighbour(page, "previous")).toContainText("Nichts davor");
        // The card IS the button, so it is the thing that is disabled.
        await expect(neighbour(page, "previous")).toBeDisabled();

        // And the next card names the track the queue will actually load.
        const promised = await page.locator(".neighbour--next .neighbour__title").textContent();
        expect(promised).toBeTruthy();

        await page.locator(".neighbour--next").click();
        await expect(page.locator(".hero-section h2")).toHaveText(promised!);
        // What was playing is now behind us.
        await expect(neighbour(page, "previous")).toContainText(playing!);
    });

    test("fetches a genre for each of the three tracks it draws, and again when they change", async ({ page }) => {
        await queueAnArtist(page);
        await page.goto("/now-playing");

        // The fixture's tracks all carry a genre, so the hero's chip is the proof the round trip
        // landed — the queue itself has no genre to give.
        await expect(page.locator(".hero-section .fact-pair", { hasText: "Genre" })).toBeVisible();
        await expect(page.locator(".neighbour--next .neighbour__fact")).toContainText(["Post-Rock"]);

        const before = await page.locator(".hero-section h2").textContent();
        await page.locator(".neighbour--next").click();
        await expect(page.locator(".hero-section h2")).not.toHaveText(before!);

        // Re-fetched for the new trio rather than left showing the old one's.
        await expect(page.locator(".hero-section .fact-pair", { hasText: "Genre" })).toBeVisible();
    });

    test("wires the analyser to the playing element", async ({ page }) => {
        await queueAnArtist(page);
        await page.goto("/now-playing");

        // The row is drawn whether or not anything plays, but an AudioContext will not RESUME
        // without a gesture — so start playback anyway, and put repeat on, because the fixture is
        // one second long and would otherwise be over before the graph is up.
        await page.locator("body").click({ position: { x: 5, y: 5 } });
        await page.keyboard.press("KeyR");
        await page.keyboard.press("KeyK");

        // `--live` is set only once `createMediaElementSource` has run against a RUNNING context,
        // which is the one thing about this component that needs an engine. See the file note for
        // why the bars themselves cannot be asserted.
        await expect(page.locator(".visualizer--live")).toBeVisible({ timeout: 10_000 });

        // THE STAGGERED COUNT, at the one width this project runs at: `devices["Desktop Chrome"]`
        // is 1280px, which is the `landscape` rung (768–1439) and therefore 32 of the 48 the widest
        // row draws. Asserting it here rather than in Vitest is the only way round — happy-dom
        // applies no scoped styles, so it can never see what a breakpoint decided. Change the
        // project's viewport and this number changes with it; the three counts live in
        // sizes/components/_visualizer.scss.
        await expect(page.locator(".visualizer__bar")).toHaveCount(32);

        // And the audio is unharmed by being routed — the risk the whole design turns on.
        await expect
            .poll(() => page.evaluate(() => document.querySelector("audio")?.paused), { timeout: 10_000 })
            .toBe(false);
    });

    test("says PAUSED at the last track, not end of queue", async ({ page }) => {
        /*
         * THE BUG THE OWNER CAUGHT, and the reason the badge does not read `hasNext`: on the LAST
         * track that is false however you arrived there, so pausing at the end of a queue
         * announced "end of queue" when a press of pause was all that had happened. The player
         * records the real event instead — a track ENDING with nothing to follow.
         *
         * Driven from the keymap rather than the bar's buttons: fewer moving parts, and the
         * shortcuts exist precisely because the transport is fiddly to aim at.
         */
        await queueAnArtist(page);
        await page.goto("/now-playing");
        await page.locator("body").click({ position: { x: 5, y: 5 } });

        // Walk the pointer to the very last row, where `hasNext` is false.
        const rows = page.locator(".np-queue .play-queue__row");
        await rows.last().locator(".play-queue__load").click();

        /*
         * The row click already STARTS playback, so one press of K pauses it — but only if playback
         * has actually begun, because K is a TOGGLE. Fired blind it used to start what had not
         * started yet, the track then ran to its end unpaused, and the badge correctly read "end of
         * queue" — the other half of the very distinction under test, which is a confusing way to
         * fail. So wait for the element, and only then press. It has to be quick either way: the
         * fixture is one second long, which is the whole reason this was ever done in one breath.
         */
        await expect
            .poll(() => page.evaluate(() => document.querySelector("audio")?.paused), { timeout: 5_000 })
            .toBe(false);
        await page.keyboard.press("KeyK");

        // Repeat is off and this is the LAST row, so `hasNext` is false — which is exactly what
        // the first version of this badge read, and why it said the wrong thing here.
        await expect(page.locator(".now-playing__status")).toHaveText("Pausiert");
    });

    test("keeps the visualiser in place while nothing is playing", async ({ page }) => {
        /*
         * IT USED TO BE MOUNTED ONLY WHILE PLAYING, on the argument that a paused EQ is a row of
         * flat bars in an empty box. What that produced was a page of four rows that became three
         * on every press of pause, with the queue below jumping a row up and back down again — so
         * the owner asked for it always (2026-08-10). A quiet baseline holding its place is both
         * true and stationary.
         */
        await queueAnArtist(page);
        await page.goto("/now-playing");

        // Nothing has played yet: the row is there, on its baseline, and NOT live — which is also
        // the proof that merely drawing it does not route the audio.
        await expect(page.locator(".visualizer")).toBeVisible();
        await expect(page.locator(".visualizer--live")).toHaveCount(0);

        // And it is still exactly one row, in exactly one place, once playback starts.
        await page.locator("body").click({ position: { x: 5, y: 5 } });
        await page.keyboard.press("KeyK");
        await expect(page.locator(".visualizer")).toHaveCount(1);
    });

    test("shows the whole queue, and jumps to a row", async ({ page }) => {
        await queueAnArtist(page);
        await page.goto("/now-playing");

        // THE PANEL'S OWN ROWS — `QueueList`, shared, so the selectors are the panel's too.
        const rows = page.locator(".np-queue .play-queue__row");
        await expect(rows).toHaveCount(10);
        // Two columns at this width: the grid takes a second one as soon as there is room for two
        // at the panel's width, and never a third.
        const columns = await page.evaluate(
            () => getComputedStyle(document.querySelector(".play-queue__list--page")!).gridTemplateColumns.split(" ").length
        );
        expect(columns).toBe(2);

        /*
         * AND THEY ARE FILLED DOWNWARDS — first half left, second half right. Only an engine can
         * answer this: the flow depends on `repeat(var(--queue-rows), auto)` resolving, which is a
         * custom property substituted into a grid template, and on the row count the component
         * publishes. Ten tracks means five a column, so row 6 (index 5) starts the right-hand one:
         * level with the FIRST row, and to the right of it. A grid dealing items across instead
         * would put row 6 on the third line down, and this comparison is what catches that.
         */
        const first = (await rows.nth(0).boundingBox())!;
        const sixth = (await rows.nth(5).boundingBox())!;
        expect(sixth.y).toBeCloseTo(first.y, 0);
        expect(sixth.x).toBeGreaterThan(first.x + first.width);

        // The divider between the columns, which exists only while there are two of them. A
        // pseudo-element, so its computed style is the only way to see it at all.
        const divider = await page.evaluate(() => {
            const style = getComputedStyle(document.querySelector(".play-queue__list--page")!, "::after");

            return { content: style.content, width: style.width };
        });
        expect(divider.content).toBe('""');
        expect(Number.parseFloat(divider.width)).toBeGreaterThan(0);

        const third = await rows.nth(2).locator(".play-queue__name").textContent();
        await rows.nth(2).locator(".play-queue__load").click();

        await expect(page.locator(".hero-section h2")).toHaveText(third!);
        await expect(rows.nth(2)).toHaveClass(/play-queue__row--current/u);
    });

    test("keeps both its grids equal when a title is far too long for one", async ({ page }) => {
        /*
         * THE BUG THE OWNER FOUND, on a real album (Burzum's *Filosofem*): a long title "reaches out
         * of the box, and messes alignment and parent width". Reported against a 54-character title
         * whose longest WORD is 15 — it is not an unbreakable string at all, and that is the point.
         * `white-space: nowrap` makes min-content equal max-content, so the whole title becomes the
         * column's floor: `1fr` means `minmax(auto, 1fr)` and that `auto` is min-content.
         *
         * BOTH OF THE PAGE'S GRIDS HAD IT, which is why they are tested together — the queue was
         * where it was spotted, and the neighbour pair was worse. Measured before the fix, at 1280px:
         * the queue's columns went 1470.75/219.64 instead of 586.5 each (517px outside its box), and
         * the two cards came out 452/765, with 247px of the row hanging off the page at 640px. Both
         * are now `minmax(0, 1fr)`.
         *
         * ONLY A BROWSER CAN SEE THIS. It is track sizing against measured text, which is precisely
         * what happy-dom does not do — a Vitest mount would report whatever the mock said.
         *
         * The title is written into the DOM rather than seeded, because the E2E library's titles are
         * all short and the fixture exists to be predictable. What is under test is the CSS, and the
         * CSS cannot tell where the text came from.
         */
        await queueAnArtist(page);
        await page.goto("/now-playing");
        await expect(page.locator(".np-queue .play-queue__row").first()).toBeVisible();

        await page.evaluate(() => {
            const title = "Rundgang Um Die Transzendentale Saule Der Singularitat";
            document.querySelectorAll(".np-queue .play-queue__name, .neighbour__title").forEach(name => {
                name.textContent = title;
            });
        });

        // 900px: columns of ~400px, where this title genuinely does not fit — which is the case that
        // used to blow both grids open, rather than the roomy 1280px default.
        await page.setViewportSize({ width: 900, height: 1000 });

        const layout = await page.evaluate(() => {
            const read = (selector: string) => {
                const grid = document.querySelector(selector)!;

                return {
                    columns: getComputedStyle(grid).gridTemplateColumns.split(" ").map(Number.parseFloat),
                    overflow: grid.scrollWidth - grid.clientWidth
                };
            };
            const name = document.querySelector(".np-queue .play-queue__name")!;

            return {
                queue: read(".play-queue__list--page"),
                neighbours: read(".now-playing__neighbours"),
                clipped: name.scrollWidth > name.clientWidth
            };
        });

        // Two columns, equal, in both grids — the assertions the old behaviour failed by 1,251px and
        // 313px respectively.
        for (const grid of [layout.queue, layout.neighbours]) {
            expect(grid.columns).toHaveLength(2);
            expect(grid.columns[0]).toBeCloseTo(grid.columns[1]!, 1);
            expect(grid.overflow).toBe(0);
        }

        // And the title is what gave way instead of the layout.
        expect(layout.clipped).toBe(true);
    });
});
