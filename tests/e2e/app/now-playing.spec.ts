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
test.use({ storageState: specStorageState("player") });
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("player");
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

        // The visualiser exists only while something is playing, so start it first — and put
        // repeat on, because the fixture is one second long and would otherwise be over before
        // the graph is up.
        await page.locator("body").click({ position: { x: 5, y: 5 } });
        await page.keyboard.press("KeyR");
        await page.keyboard.press("KeyK");

        // `--live` is set only once `createMediaElementSource` has run against a RUNNING context,
        // which is the one thing about this component that needs an engine. See the file note for
        // why the bars themselves cannot be asserted.
        await expect(page.locator(".visualizer--live")).toBeVisible({ timeout: 10_000 });
        await expect(page.locator(".visualizer__bar")).toHaveCount(48);

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

        // The row click already STARTS playback, so one press of K pauses it. Done in the same
        // breath because the fixture is one second long: let it run out and the badge would
        // correctly read "end of queue", which is the other half of the distinction being tested.
        await page.keyboard.press("KeyK");

        // Repeat is off and this is the LAST row, so `hasNext` is false — which is exactly what
        // the first version of this badge read, and why it said the wrong thing here.
        await expect(page.locator(".now-playing__status")).toHaveText("Pausiert");
    });

    test("hides the visualiser while nothing is playing", async ({ page }) => {
        // A paused EQ is a row of flat bars saying nothing, inside an empty box.
        await queueAnArtist(page);
        await page.goto("/now-playing");

        await expect(page.locator(".visualizer")).toHaveCount(0);

        await page.locator("body").click({ position: { x: 5, y: 5 } });
        await page.keyboard.press("KeyK");
        await expect(page.locator(".visualizer")).toBeVisible();
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

        const third = await rows.nth(2).locator(".play-queue__name").textContent();
        await rows.nth(2).locator(".play-queue__load").click();

        await expect(page.locator(".hero-section h2")).toHaveText(third!);
        await expect(rows.nth(2)).toHaveClass(/play-queue__row--current/u);
    });
});
