import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { enqueueFromHero, openQueuePanel, pageHeading, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * The play queue's LAYOUT, which is the half no other test layer can see.
 *
 * Three things here need a real browser and a real viewport:
 *
 *   - the footer and the player bar are alternatives. Vitest can prove PlayerBar renders
 *     when a track is loaded, but not that the footer left with it.
 *   - the panel takes 280px out of <main>, and the DataTable's table-or-cards switch is a
 *     CONTAINER query on that remaining width. Whether a listing survives with the queue
 *     open is a question about layout, and happy-dom has none. This is the specific
 *     regression that made the breakpoint move from `desktop` to `landscape`.
 *   - the panel has two entirely different shapes — a column beside the page, and a bottom
 *     sheet over it — chosen by viewport width.
 *
 * The queue also has to survive an Inertia navigation, which is the reason it and the
 * player live in the layout rather than in a page.
 */

/*
 * ITS OWN ACCOUNT, AND A CLEAN QUEUE PER TEST. The play queue is server state since the
 * `player_states` sync landed, so a fresh browser context is no longer a fresh player: a
 * queue follows the USER. Sharing one account across files let a spec in one worker restore
 * a queue another worker had just left, and sharing it across tests in this file let each
 * test inherit the last one's. The account is this file's alone (E2ESeeder seeds it,
 * auth.setup mints its session) and the reset below is what tests here owe each other.
 */
test.use({ storageState: specStorageState("queue") });

/*
 * SEQUENTIAL, IN ONE WORKER, which the account above is worthless without. `fullyParallel`
 * parallelises at the TEST level, not the file level — so without this the tests in this
 * file run CONCURRENTLY against the one account they share, and each sees the others'
 * queues. That failed as a count one too high, on a different test every run.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("queue");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`,
// so that request can outlive the test and land after the NEXT one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** Open a song's page and put it in the queue. Returns the song's title. */
const enqueueFirstSong = async (page: import("@playwright/test").Page): Promise<string> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    const title = await pageHeading(page).innerText();
    await enqueueFromHero(page);
    // Opened explicitly, and DELIBERATELY rather than by the peek the enqueue would otherwise
    // leave standing: the panel is an overlay toggled from the header at every width now, so
    // having a queue no longer puts it on screen. Almost every test below reads the rows, so it
    // belongs here rather than at each call site — and a panel opened here carries no pending
    // auto-close, which a peeked one does.
    await openQueuePanel(page);

    return title;
};

test.describe("the play queue", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("wipes in from the edge it is pinned to, and holds itself open to wipe out", async ({ page }) => {
        /*
         * The panel is REVEALED rather than moved: `clip-path` uncovers it from the trailing edge,
         * so nothing translates and the rows are already in place. Caught mid-gesture by hand at
         * `inset(0px 0px 0px 51.1379%)`, which is the proof it transitions rather than snapping —
         * not asserted here, because polling a value that is mid-flight is a coin toss.
         *
         * What IS assertable is the wiring, and the half that is easy to leave out: the discrete
         * `display`/`overlay` pair lives on the LAYER, not the panel. Without it, closing yanks
         * the popover from the top layer on the same frame and the exit is cut off.
         */
        await enqueueFirstSong(page);
        await openQueuePanel(page);

        const wiring = await page.evaluate(() => ({
            layer: getComputedStyle(document.querySelector(".play-queue-layer")!).transitionProperty,
            panel: getComputedStyle(document.querySelector(".play-queue")!).transitionProperty
        }));

        expect(wiring.layer).toContain("display");
        expect(wiring.layer).toContain("overlay");
        expect(wiring.panel).toContain("clip-path");
    });

    test("lights its edge on a peek, and not when the reader asked for it", async ({ page }) => {
        /*
         * THE TWO ENTRANCES. A peek is an announcement — nobody asked, the panel appeared to say
         * something was queued — so it gets a light down its inner edge. A press of the toggle is
         * a request, and stays calm.
         */
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        // `keepPeek` leaves the peek on screen — the whole point of this test.
        await enqueueFromHero(page, { keepPeek: true });

        const peeking = await page.evaluate(() => ({
            marked: document.querySelector(".play-queue-layer")!.classList.contains("play-queue-layer--peek"),
            sweep: getComputedStyle(document.querySelector(".play-queue")!, "::after").animationName
        }));

        expect(peeking.marked).toBe(true);
        expect(peeking.sweep).not.toBe("none");

        // Let the peek expire, then ask for the panel deliberately.
        await expect(page.locator(".play-queue-layer")).toBeHidden({ timeout: 8_000 });
        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue-layer")).toBeVisible();

        const asked = await page.evaluate(() => ({
            marked: document.querySelector(".play-queue-layer")!.classList.contains("play-queue-layer--peek"),
            sweep: getComputedStyle(document.querySelector(".play-queue")!, "::after").animationName
        }));

        expect(asked.marked).toBe(false);
        expect(asked.sweep).toBe("none");
    });

    test("declines the whole thing for a reader who asked for less motion", async ({ page }) => {
        // No wipe and no sweep — the panel is simply there. `clip-path` is inside the motion guard
        // rather than being reset under it, so there is nothing clipping it at all.
        await page.emulateMedia({ reducedMotion: "reduce" });
        await enqueueFirstSong(page);
        await openQueuePanel(page);

        const still = await page.evaluate(() => ({
            clip: getComputedStyle(document.querySelector(".play-queue")!).clipPath,
            sweep: getComputedStyle(document.querySelector(".play-queue")!, "::after").animationName
        }));

        expect(still.clip).toBe("none");
        expect(still.sweep).toBe("none");
    });

    test("advertises its keyboard shortcut on the toggle", async ({ page }) => {
        /*
         * The Q shortcut has no other affordance anywhere — the panel does not mention it and
         * nothing else in the header carries a hint — so this tooltip is the whole of its
         * discoverability. Only a browser can answer it: the text lives in a WeakMap inside the
         * directive rather than on the element, and it is a real hover into a top-layer element.
         *
         * It says "toggle" rather than what the next press does, unlike the button's own
         * `aria-label`, which flips with the state: a screen reader should hear what pressing
         * will do NOW, while a key that toggles is described once.
         */
        // `enqueueFirstSong` leaves the panel shut for us — the peek would otherwise still be
        // sliding over the header when the hover lands.
        await enqueueFirstSong(page);

        /*
         * A MOVE ONTO the button, not a jump to it. The directive only accepts a hover from a
         * real mouse and tracks pointer movement to tell one from the emulated hover a tap
         * produces, so the pointer has to come from somewhere. Then its 300ms open delay.
         */
        await page.mouse.move(10, 400);
        await page.locator(".play-queue-toggle").hover();

        await expect(page.locator("[role='tooltip']")).toHaveText(/\(Q\)/u, { timeout: 10_000 });
    });

    test("shows nothing until something is queued", async ({ page }) => {
        await page.goto("/music/songs");

        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".player-bar")).toHaveCount(0);
        await expect(page.locator("footer")).toBeVisible();
    });

    test("replaces the footer with the player bar once a track is loaded", async ({ page }) => {
        await enqueueFirstSong(page);

        await expect(page.locator(".player-bar")).toBeVisible();
        // The two are alternatives, not neighbours.
        await expect(page.locator("footer")).toHaveCount(0);
    });

    test("puts the enqueued song in the panel and in the bar", async ({ page }) => {
        const title = await enqueueFirstSong(page);

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".play-queue__name")).toHaveText(title);
        await expect(page.locator(".player-bar__name")).toHaveText(title);
    });

    test("survives a navigation, because the queue lives in the layout", async ({ page }) => {
        await enqueueFirstSong(page);

        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".player-bar")).toBeVisible();
    });

    test("leaves a listing enough width to still be a table", async ({ page }) => {
        /*
         * The reason the DataTable's container breakpoint moved to `landscape`. This runs at
         * 1440px, where the panel is its wider 360px, leaving <main> about 1072px — still
         * clear of the 768px container line. Under the old `desktop` (1024px) line it would
         * not be, which is the regression that moved the breakpoint: a 1280px laptop tipped
         * over it and every listing in the app turned into cards the moment anything was
         * queued.
         */
        await enqueueFirstSong(page);

        await page.goto("/music/songs");
        await expect(page.locator("table")).toBeVisible();
        await expect(page.locator(".dt-cards")).toBeHidden();
    });

    test("peeks itself open when something is queued, then puts itself away", async ({ page }) => {
        /*
         * The behaviour's point is that nobody pressed anything: the panel reveals what was
         * just added and then gets out of the way. The three seconds are asserted by WAITING
         * — a fake clock would only prove the setTimeout — and the browser is the only layer
         * that can say the panel was genuinely on screen, the top layer being its whole
         * mechanism. PlayQueue's own spec covers the branches (growth only, a deliberately
         * open panel left alone, a touch cancelling the close) on a fake clock, cheaply.
         */
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        const panel = page.locator(".play-queue");
        await expect(panel).toHaveCount(0);

        await enqueueFromHero(page, { keepPeek: true });

        // Opened by nothing but the enqueue.
        await expect(panel).toBeVisible();
        await expect(page.locator(".play-queue-toggle")).toHaveAttribute("aria-expanded", "true");

        // And gone again on its own. Generous, because the wait is real: the peek is 3s.
        await expect(panel).toBeHidden({ timeout: 8_000 });
        await expect(page.locator(".play-queue-toggle")).toHaveAttribute("aria-expanded", "false");
    });

    test("light-dismisses: Escape, a click outside, and the header follows either", async ({ page }) => {
        /*
         * The panel is a native `[popover]`, which is what buys all of this — and browser
         * behaviour is only observable in a browser, so this is the only layer that can check
         * it. Three paths, one mechanism (Chrome routes them through CloseWatcher): Escape, a
         * click outside, and on Android the back gesture. The back gesture is the one this
         * cannot drive — desktop Chromium has no such input, and `goBack()` would navigate
         * rather than dismiss — so it is covered by the same attribute and nothing else.
         *
         * THE HEADER FOLLOWING IS THE HALF THAT BROKE FIRST. The browser can close the panel
         * without anything in the app being asked, so PlayQueue mirrors the element's `toggle`
         * event back into usePlayQueuePanel. Bound with an `onMounted` `addEventListener` it
         * silently never fired — the panel is `v-if`d on a non-empty queue, so at mount there
         * was no element to bind to — and Escape closed the panel while the header went on
         * offering a close icon for it. Hence `aria-expanded` in both halves below.
         */
        await enqueueFirstSong(page);
        const panel = page.locator(".play-queue");
        const toggle = page.locator(".play-queue-toggle");

        await expect(toggle).toHaveAttribute("aria-expanded", "true");

        await page.keyboard.press("Escape");
        await expect(panel).toBeHidden();
        await expect(toggle).toHaveAttribute("aria-expanded", "false");

        await openQueuePanel(page);
        await page.locator("main").click({ position: { x: 200, y: 200 } });
        await expect(panel).toBeHidden();
        await expect(toggle).toHaveAttribute("aria-expanded", "false");
    });

    test("stays open when the click lands inside it, or on its own menu", async ({ page }) => {
        /*
         * The other half of light dismiss, and not a given: the queue's menu is a popover
         * INSIDE a popover. Nesting is what keeps both up — an `auto` popover closes every
         * other `auto` popover that is not its ancestor, so were the menu anywhere but a
         * descendant of the panel, opening it would dismiss the panel underneath it.
         */
        await enqueueFirstSong(page);
        const panel = page.locator(".play-queue");

        await page.locator(".play-queue__header").click({ position: { x: 5, y: 5 } });
        await expect(panel).toBeVisible();

        await page.locator(".play-queue .popover-button").click();
        await expect(page.locator(".play-queue .popover-list-item").first()).toBeVisible();
        await expect(panel).toBeVisible();
    });

    test("keeps the grip's tooltip above the panel, now that both are in the top layer", async ({ page }) => {
        /*
         * A CONSEQUENCE OF THE PANEL BEING PROMOTED, checked rather than assumed. The tooltip
         * layer is itself a `popover="manual"`, so both live in the top layer — where painting
         * order is promotion order, not z-index. It works because useTooltipLayer hides and
         * re-shows the one tip per trigger, so every tooltip is promoted after the panel; a
         * tip that was merely repositioned would have stayed underneath it.
         */
        await enqueueFirstSong(page);

        await page.locator(".play-queue__grip").first().hover();

        const tip = page.locator("[role='tooltip']");
        await expect(tip).toBeVisible();

        // Overlapping the panel rather than tucked behind its edge.
        const boxes = await page.evaluate(() => {
            const t = document.querySelector("[role='tooltip']")!.getBoundingClientRect();
            const p = document.querySelector(".play-queue")!.getBoundingClientRect();

            return { tipRight: Math.round(t.right), panelLeft: Math.round(p.left) };
        });
        expect(boxes.tipRight).toBeGreaterThan(boxes.panelLeft);
    });

    test("widens at `full`, and moves the content not at all", async ({ page }) => {
        /*
         * This describe runs at 1440px, which IS the `full` line — so the panel is 360px
         * here and 280px below it (asserted at 420px in the narrow-screen block).
         *
         * The second assertion REPLACED its own opposite. The panel used to inset the content
         * (FullLayout published `--content-inset-end`, Container applied it) and this test
         * checked the two numbers had not drifted apart. Now that it overlays at every width,
         * the thing worth pinning is that opening it moves nothing: same content box open and
         * shut, which is the whole reason an overlay was the half kept when the dashboard
         * forced the choice.
         */
        await enqueueFirstSong(page);
        await page.goto("/music/songs");
        await openQueuePanel(page);

        const panel = (await page.locator(".play-queue").boundingBox())!;
        expect(Math.round(panel.width)).toBe(360);

        const contentBox = async () =>
            page.evaluate(() => {
                const content = document.querySelector("main .container") ?? document.querySelector("main");
                const box = content!.getBoundingClientRect();
                const style = getComputedStyle(content!);

                return { right: Math.round(box.right - parseFloat(style.paddingRight)), width: Math.round(box.width) };
            });

        const open = await contentBox();
        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeHidden();
        const shut = await contentBox();

        expect(open).toStrictEqual(shut);
        // And it really is over the content, not beside it.
        expect(open.right).toBeGreaterThan(panel.x);
    });

    test("empties back to the footer when the queue is cleared", async ({ page }) => {
        await enqueueFirstSong(page);

        await page.locator(".play-queue .popover-button").click();
        // By the `--caution` variant, not by position: the repeat toggle sits above it in
        // the menu now, and a bare `.popover-list-item` matches both.
        await page.locator(".play-queue .popover-list-item--caution").click();

        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator(".player-bar")).toHaveCount(0);
        await expect(page.locator("footer")).toBeVisible();
    });
});

test.describe("the play queue synced to the server", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("comes back on a browser whose storage was wiped, because the server had it", async ({ page }) => {
        /*
         * THE ONE ASSERTION THAT CANNOT BE FAKED. Vitest can prove a PUT was sent and PHPUnit
         * can prove a row was written; only this can show the round trip working end to end —
         * through the real route, the real CSRF token off the shared prop (a 419 here would be
         * invisible in either of the other layers), and the real hydration path on the way
         * back.
         *
         * CLEARING localStorage IS WHAT MAKES IT A TEST. The queue survives an ordinary reload
         * from the browser's own copy, so a plain reload would pass with the server half
         * removed entirely. Wiping storage first leaves the server as the only place the queue
         * can possibly come from — which is also exactly what a second device looks like.
         */
        /*
         * ARMED BEFORE THE ENQUEUE, which it was not until 2026-08-08. The sync rides the
         * queue's coalesced trailing flush, so waiting for the request AFTER the action that
         * causes it is a race — and it became a lost one when `enqueueFirstSong` grew a step
         * (it opens the panel now), widening the window enough for the PUT to land before
         * anything was listening. Registering first cannot race.
         */
        const synced = page.waitForResponse(
            response => response.url().includes("/player/state") && response.request().method() === "PUT"
        );
        const title = await enqueueFirstSong(page);
        await synced;

        await page.evaluate(() => window.localStorage.clear());
        await page.reload();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".play-queue__row").first()).toContainText(title);
        // …and the bar is back with it, loaded and ready rather than merely listed.
        await expect(page.locator(".player-bar__name")).toContainText(title);
    });

    test("clears on the server too, so the other device does not restore it forever", async ({ page }) => {
        // Armed first, for the reason the test above sets out.
        const synced = page.waitForResponse(
            response => response.url().includes("/player/state") && response.request().method() === "PUT"
        );
        await enqueueFirstSong(page);
        await synced;

        await page.locator(".play-queue .popover-button").click();
        await page.locator(".play-queue .popover-list-item--caution").click();
        await page.waitForResponse(
            response => response.url().includes("/player/state") && response.request().method() === "PUT"
        );

        await page.evaluate(() => window.localStorage.clear());
        await page.reload();

        await expect(page.locator(".play-queue")).toHaveCount(0);
        await expect(page.locator("footer")).toBeVisible();
    });
});

test.describe("the play queue at library scale", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("skips the rows nobody is looking at, and still measures them right", async ({ page }) => {
        /*
         * Bulk enqueue made a thousand-row queue something one click can produce, and the
         * panel renders every row — so `content-visibility: auto` lets the browser skip the
         * ones off screen. Measured at 2,000 rows (2026-08-07): main-thread blocking during
         * load fell from 810ms to 302ms, the longest single task from 331ms to 151ms, and
         * first paint from 528ms to 268ms.
         *
         * THE TIMINGS ARE NOT WHAT THIS ASSERTS — a threshold in milliseconds is a flake
         * waiting for a busy machine. What it pins is the pair that has to agree: the
         * property being on, and `contain-intrinsic-size` matching what a row really is.
         * Get the second one wrong and nothing looks broken — the scrollbar simply lies, in
         * proportion to how far off the estimate is. It was wrong once, by the row's own
         * padding (54px instead of 42px), and the list came out a fifth too tall.
         *
         * Seeded through storage rather than by queueing 200 songs: the subject is the
         * panel at scale, and the enqueue path has its own tests.
         */
        const rows = 200;
        const rowHeight = 54;

        await enqueueFirstSong(page);
        // The queue writes late (a trailing flush), so the payload this test rewrites does
        // not exist the instant the row appears.
        await expect
            .poll(() => page.evaluate(() => window.localStorage.getItem("mixtape.queue") !== null))
            .toBe(true);

        await page.evaluate(count => {
            // The stored payload the app just wrote, so the user it belongs to is right.
            const stored = JSON.parse(window.localStorage.getItem("mixtape.queue")!);
            stored.tracks = Array.from({ length: count }, (_, index) => ({
                id: `00000000-0000-4000-8000-${String(index).padStart(12, "0")}`,
                name: `Track number ${index}`,
                artist: "Some Artist",
                album: "Some Album",
                duration: 210
            }));
            window.localStorage.setItem("mixtape.queue", JSON.stringify(stored));
        }, rows);
        await page.reload();
        await expect(page.locator(".play-queue__row")).toHaveCount(rows);
        // A reload shuts the panel — usePlayQueuePanel is deliberately not persisted, so that
        // every visit starts with the content unobstructed — and a closed panel has no
        // geometry to measure.
        await openQueuePanel(page);

        const measured = await page.evaluate(() => {
            const row = document.querySelector(".play-queue__row") as HTMLElement;
            const list = document.querySelector(".play-queue__list") as HTMLElement;

            return {
                skipping: getComputedStyle(row).contentVisibility,
                scrollHeight: list.scrollHeight,
                renderedRowHeight: row.getBoundingClientRect().height
            };
        });

        expect(measured.skipping).toBe("auto");
        // A rendered row and a skipped one have to be the same height, or the scrollbar
        // lies about how much queue there is.
        expect(Math.abs(measured.renderedRowHeight - rowHeight)).toBeLessThan(1);
        expect(Math.abs(measured.scrollHeight - rows * rowHeight)).toBeLessThan(rows);
    });
});

test.describe("the play queue on a narrow screen", () => {
    test.use({ viewport: { width: 420, height: 850 } });

    /** Queue a song from its page. At this width the listing is a card grid, not a table. */
    const enqueueFromCard = async (page: import("@playwright/test").Page): Promise<void> => {
        await page.goto("/music/songs");
        await page.locator(".dt-cards a").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await enqueueFromHero(page);
    };

    test("keeps the panel shut until the header's toggle opens it", async ({ page }) => {
        /*
         * The whole point of the narrow layout: 280px of panel on a 420px screen is
         * most of it, so the queue is not something you carry around open. Queuing a
         * song must not shove the page aside.
         *
         * Adding to the queue PEEKS the panel for three seconds, which `enqueueFromHero` puts
         * away again — so "shut" here is the resting state, not the instant after the enqueue.
         */
        await enqueueFromCard(page);

        await expect(page.locator(".play-queue")).toBeHidden();
        await expect(page.locator(".play-queue-toggle")).toBeVisible();

        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeVisible();

        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeHidden();
    });

    test("floats over the content instead of taking a column from it", async ({ page }) => {
        // An overlay, so the page behind keeps its full width — the bottom sheet this
        // replaced permanently ate half the viewport and had to be scrolled past.
        await enqueueFromCard(page);
        await page.goto("/music/songs");
        const closed = (await page.locator("main").boundingBox())!;

        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeVisible();
        const open = (await page.locator("main").boundingBox())!;

        expect(Math.round(open.width)).toBe(Math.round(closed.width));
        // Same place as on a desktop: top right, under the header.
        const panel = (await page.locator(".play-queue").boundingBox())!;
        // `.app-header`, not `header` — the panel has a <header> of its own.
        const header = (await page.locator("header.app-header").boundingBox())!;
        expect(Math.round(panel.width)).toBe(280);
        expect(Math.round(panel.y)).toBe(Math.round(header.y + header.height));
    });

    test("swaps the toggle's glyph for a close once it is open", async ({ page }) => {
        await enqueueFromCard(page);
        const toggle = page.locator(".play-queue-toggle");

        // Icon writes `xlink:href`, and a real browser will not hand that back under
        // the plain `href` name the way happy-dom does — hence the qualified read.
        const glyph = () => toggle.locator("use").evaluate(el => el.getAttribute("xlink:href"));

        expect(await glyph()).toBe("#play_queue");
        await expect(toggle).toHaveAttribute("aria-expanded", "false");

        await toggle.click();

        expect(await glyph()).toBe("#close");
        await expect(toggle).toHaveAttribute("aria-expanded", "true");
    });

    test("stays joined to the player bar across a resize", async ({ page }) => {
        /*
         * The bar's height is rem-based and this app sets a different root font-size per
         * breakpoint, so the bar is ~61.6px on a phone and ~62.4px a step up. PlayerBar
         * published `--app-player-height` ONCE at mount, so dragging the window across a
         * breakpoint left the panel pinning its bottom edge to a stale number and a
         * sliver of page showed through between the two. Sub-pixel, and on a light panel
         * over a light page it reads as a seam. A ResizeObserver keeps it honest.
         */
        await enqueueFromCard(page);
        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeVisible();

        const gap = () =>
            page.evaluate(() => {
                const panel = document.querySelector(".play-queue")!.getBoundingClientRect();
                const bar = document.querySelector(".player-bar")!.getBoundingClientRect();

                return bar.top - panel.bottom;
            });

        for (const width of [700, 420, 760, 380]) {
            await page.setViewportSize({ width, height: 850 });
            await expect.poll(gap).toBe(0);
        }
    });

    test("offers no toggle while the queue is empty", async ({ page }) => {
        // It would open nothing — an empty queue draws no panel at all.
        await page.goto("/music/songs");

        await expect(page.locator(".play-queue-toggle")).toHaveCount(0);
    });
});

test.describe("reordering the play queue", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    /*
     * THE ONLY LAYER THAT CAN SEE A DRAG. Vitest mocks SortableJS deliberately — a drag is
     * a stream of pointer events over elements with real geometry, and happy-dom has
     * neither, so a "drag" there would assert the mock's arithmetic. What is left for a
     * real browser is the gesture itself: that pressing the grip and moving the pointer
     * actually moves the row, that the drop is applied once (a wrapper-less SortableJS
     * integration duplicates or loses a row when the DOM move is not undone first), and
     * that the pointer stays on the track that was loaded.
     *
     * Sortable runs with `forceFallback`, which is what makes this drivable: its own
     * mouse/pointer path rather than native HTML5 dragging, so plain mouse moves work.
     */

    /** Queue `count` songs off the listing, in listing order, and return their titles. */
    const enqueueSongs = async (page: import("@playwright/test").Page, count: number): Promise<string[]> => {
        const titles: string[] = [];

        for (let row = 0; row < count; row += 1) {
            await page.goto("/music/songs");
            await page.locator("tbody tr").nth(row).click();
            await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
            titles.push(await pageHeading(page).innerText());
            await enqueueFromHero(page);
        }
        await expect(page.locator(".play-queue__row")).toHaveCount(count);
        // The rows exist as soon as the queue does; being ON SCREEN is a separate fact now,
        // and a drag needs real geometry.
        await openQueuePanel(page);

        return titles;
    };

    /** The queue's titles, in the order the panel shows them. */
    /**
     * The queue's titles in DOM order.
     *
     * `allTextContents`, NOT `allInnerTexts`, and that distinction is load-bearing here: the
     * rows carry `content-visibility: auto` (see the list's styles — it is what makes a
     * 12,000-track queue cheap), and `innerText` is EMPTY for a subtree the browser has
     * skipped. That never showed while the panel stood permanently open at this width, because
     * the rows had long since been rendered; now that it is opened moments before the drag, a
     * freshly-shown row can still be skipped when the assertion first looks — the drag had
     * worked and the order read `["(Nice Dream)", "", ""]`. `textContent` is parsed text and
     * owes nothing to layout, which is exactly what an ordering assertion wants.
     */
    const order = async (page: import("@playwright/test").Page): Promise<string[]> =>
        (await page.locator(".play-queue__name").allTextContents()).map(text => text.trim());

    /**
     * Drag the row at `from` onto the row at `to`, by its grip.
     *
     * Stepped rather than one jump, and past the far edge of the destination: Sortable
     * decides where to insert from where the pointer sits inside the row it is over, and
     * it only sees positions the pointer actually visits.
     */
    const dragRow = async (page: import("@playwright/test").Page, from: number, to: number): Promise<void> => {
        const grip = (await page.locator(".play-queue__grip").nth(from).boundingBox())!;
        const target = (await page.locator(".play-queue__row").nth(to).boundingBox())!;
        const downwards = to > from;

        await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
        await page.mouse.down();
        // Clear `fallbackTolerance` first, so the press is read as a drag and not a click.
        await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 10, { steps: 4 });
        await page.mouse.move(
            target.x + target.width / 2,
            target.y + target.height * (downwards ? 0.8 : 0.2),
            { steps: 16 }
        );
        await page.mouse.up();
    };

    test("moves a row to the top by dragging its grip", async ({ page }) => {
        const [first, second, third] = await enqueueSongs(page, 3);
        expect(await order(page)).toStrictEqual([first, second, third]);

        await dragRow(page, 2, 0);

        await expect.poll(() => order(page)).toStrictEqual([third, first, second]);
        // Applied ONCE: the count is what catches a DOM move that was not undone before
        // the queue re-rendered over it.
        await expect(page.locator(".play-queue__row")).toHaveCount(3);
    });

    test("keeps the loaded track loaded, and marked, when a row moves above it", async ({ page }) => {
        /*
         * The reason both gestures go through `usePlayerQueue().reorder()`: it carries the
         * pointer with the track that was LOADED. Dragging something from below the playing
         * row to above it shifts that row's index by one, and an index-based pointer would
         * quietly hand the player a different song — while still playing the old one.
         */
        const [first, second, third] = await enqueueSongs(page, 3);
        await expect(page.locator(".player-bar__name")).toHaveText(first);

        await dragRow(page, 2, 0);

        await expect.poll(() => order(page)).toStrictEqual([third, first, second]);
        await expect(page.locator(".player-bar__name")).toHaveText(first);
        // Row 2 is where the loaded track ended up: it was first, and one row jumped over it.
        await expect(page.locator(".play-queue__row--current .play-queue__name")).toHaveText(first);
        await expect(page.locator(".play-queue__row").nth(1)).toHaveClass(/play-queue__row--current/u);
    });

    test("survives a navigation, because the new order is persisted", async ({ page }) => {
        // reorder() commits to localStorage like every other queue operation; the panel
        // lives in the layout, so an Inertia visit must not resurrect the old order.
        const [first, second] = await enqueueSongs(page, 2);

        await dragRow(page, 1, 0);
        await expect.poll(() => order(page)).toStrictEqual([second, first]);

        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        expect(await order(page)).toStrictEqual([second, first]);
    });

    test("moves a row with Alt+↑/↓ after the grip is clicked, and follows it with focus", async ({ page }) => {
        /*
         * The keyboard companion, in a real browser — where `Alt+ArrowDown` is a genuine
         * keystroke rather than a synthesised event, and where the browser has its own
         * ideas about Alt+arrow. Focus following the row is what makes it usable twice in
         * a row, and it is the half that would break silently: the row's key carries its
         * index, so the element holding focus is replaced by the move.
         *
         * It starts with a CLICK rather than `focus()` on purpose. The shortcut acts on
         * the focused row, so the journey a reader actually takes is click-then-press —
         * which is what the grip's hint tells them to do, and what looked broken on a Mac
         * before the grip focused itself on pointerdown (Safari and Firefox there leave a
         * clicked button unfocused by platform convention).
         */
        const [first, second, third] = await enqueueSongs(page, 3);
        const grip = (row: number) => page.locator(".play-queue__grip").nth(row);

        await grip(0).click();
        await expect(grip(0)).toBeFocused();
        await page.keyboard.press("Alt+ArrowDown");

        await expect.poll(() => order(page)).toStrictEqual([second, first, third]);
        await expect(grip(1)).toBeFocused();

        await page.keyboard.press("Alt+ArrowDown");

        await expect.poll(() => order(page)).toStrictEqual([second, third, first]);
        await expect(grip(2)).toBeFocused();

        // …and back up, from wherever it ended up.
        await page.keyboard.press("Alt+ArrowUp");

        await expect.poll(() => order(page)).toStrictEqual([second, first, third]);
        await expect(grip(1)).toBeFocused();
    });

    test("tells the reader how to reorder, in the keys their keyboard prints", async ({ page }) => {
        /*
         * The hint is load-bearing rather than decoration: nothing else says the shortcut
         * needs the grip CLICKED first, and hovering a row and pressing the keys does
         * nothing at all (hover is not focus) — which is how a working feature came to be
         * reported as broken. The keys are named per platform, so this asserts the shape
         * rather than one spelling: ⌥↑/↓ on a Mac, Alt+↑/↓ elsewhere. What it really
         * guards is that the interpolation happened — a missing parameter would leave a
         * literal "{keys}" sitting in the tip.
         */
        await enqueueSongs(page, 2);

        await page.locator(".play-queue__grip").first().hover();

        const tip = page.locator("#app-tooltip");
        await expect(tip).toBeVisible();
        await expect(tip).toHaveText(/(⌥|Alt\+)↑\/↓/u);
        await expect(tip).not.toContainText("{keys}");
    });

    test("does not move the page, or the queue, at the ends", async ({ page }) => {
        // The keystroke is deliberately NOT consumed at the ends of the queue. That is
        // only safe if it does nothing else either — Alt+arrow is close enough to the
        // browser's own history shortcut to be worth proving once.
        const [first, second] = await enqueueSongs(page, 2);
        const url = page.url();

        await page.locator(".play-queue__grip").first().focus();
        await page.keyboard.press("Alt+ArrowUp");

        expect(await order(page)).toStrictEqual([first, second]);
        expect(page.url()).toBe(url);
    });
});

test.describe("the play queue scrolling to the loaded track", () => {
    /*
     * Short viewport ON PURPOSE: scrolling only exists when the queue is longer than the
     * list, and ~420px leaves room for four or five rows. Width stays at `full` so the panel
     * is at its widest; it is opened like everywhere else (enqueueFirstSong does it).
     */
    test.use({ viewport: { width: 1440, height: 420 } });

    test("brings the loaded track into view, a row clear of the edge it approached", async ({ page }) => {
        /*
         * The behaviour only a browser can check: next/prev and auto-advance move the
         * pointer without anyone touching the list, so the row that is now playing can sit
         * outside the scrollport entirely.
         *
         * Advanced with the bar's NEXT button rather than by clicking rows, because a click
         * in the list proves nothing — you cannot click a row you cannot see. And asserted
         * on a MIDDLE track: at the very end of the list there is no content left to scroll,
         * so the browser clamps and the margin cannot be honoured. That is correct, not a
         * bug, but it makes the last row the wrong place to measure.
         */
        const song = await enqueueFirstSong(page);

        /*
         * SHUT WHILE QUEUEING, and this is the overlay's real cost rather than a test quirk:
         * the panel covers the trailing edge of the page, which on a detail page is exactly
         * where the hero's action menu sits — the control this queues with. It was reachable
         * while the content was inset to clear the panel; now a reader queueing more tracks
         * closes the panel first, and so does this test. `enqueueFirstSong` opened it, so this
         * click is a plain close with no peek timer behind it to reopen anything.
         */
        await page.locator(".play-queue-toggle").click();
        await expect(page.locator(".play-queue")).toBeHidden();

        // The same track eight times — duplicates are a normal queue, and it saves seven
        // page loads over queueing eight different songs.
        for (let i = 0; i < 7; i += 1) await enqueueFromHero(page);
        await openQueuePanel(page);
        await expect(page.locator(".play-queue__row")).toHaveCount(8);
        await expect(page.locator(".player-bar__name")).toHaveText(song);

        // prev, play, next — in DOM order; only the middle one carries a modifier class.
        const next = page.locator(".player-bar__control").nth(2);
        for (let i = 0; i < 5; i += 1) await next.click();
        await expect(page.locator(".play-queue__row--current")).toHaveAttribute("aria-current", "true");

        const geometry = () =>
            page.evaluate(() => {
                const list = document.querySelector(".play-queue__list")!.getBoundingClientRect();
                const row = document.querySelector(".play-queue__row--current")!.getBoundingClientRect();

                return { above: row.top - list.top, below: list.bottom - row.bottom, height: row.height };
            });

        // Polled, because the scroll is SMOOTH — it is still animating when the click resolves.
        await expect.poll(async () => (await geometry()).below >= (await geometry()).height * 0.9).toBe(true);

        const { above, below, height } = await geometry();
        // Inside the scrollport at both ends...
        expect(above).toBeGreaterThanOrEqual(0);
        expect(below).toBeGreaterThanOrEqual(0);
        // ...and a row's height of context below it, having travelled down to get there.
        expect(below).toBeGreaterThanOrEqual(height * 0.9);
    });
});

test.describe("the play queue from landscape up", () => {
    test.use({ viewport: { width: 900, height: 850 } });

    test("stays shut until the header's toggle is pressed, exactly as on a phone", async ({ page }) => {
        /*
         * THE BEHAVIOUR THAT CHANGED, and the reason it did. This width used to get a panel
         * that was simply there, with the toggle hidden and the content inset to clear it.
         * That could not be squared with the dashboard, whose headings are RIGHT-aligned: no
         * trailing room to give, so the panel overlaid the content there and sat beside it
         * everywhere else. One behaviour at every width was the answer.
         */
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await enqueueFromHero(page);

        // A queue, but no panel — and a toggle that is now offered at this width too.
        await expect(page.locator(".play-queue")).toBeHidden();
        const toggle = page.locator(".play-queue-toggle");
        await expect(toggle).toBeVisible();

        await toggle.click();
        await expect(page.locator(".play-queue")).toBeVisible();

        // And it closes again from the same button.
        await toggle.click();
        await expect(page.locator(".play-queue")).toBeHidden();
    });

    test("spans header to player bar however short the queue is", async ({ page }) => {
        /*
         * At this width the panel used to be only as tall as its contents, so its bottom
         * edge landed wherever the list happened to reach and MOVED every time something
         * was queued or removed — which read as a dropdown that had failed to close rather
         * than a fixture of the layout.
         *
         * Asserted with a SINGLE track on purpose: that is the case that used to differ, and
         * a long queue would fill the height either way and prove nothing. Both ends are
         * checked, because full height is only right if it starts under the header AND
         * finishes on the bar — the narrow-screen spec above covers the bottom edge only.
         */
        await enqueueFirstSong(page);
        await expect(page.locator(".play-queue__row")).toHaveCount(1);

        const edges = await page.evaluate(() => {
            const panel = document.querySelector(".play-queue")!.getBoundingClientRect();
            const header = document.querySelector("header.app-header")!.getBoundingClientRect();
            const bar = document.querySelector(".player-bar")!.getBoundingClientRect();

            return { top: panel.top - header.bottom, bottom: bar.top - panel.bottom };
        });

        expect(Math.round(edges.top)).toBe(0);
        expect(Math.round(edges.bottom)).toBe(0);
    });
});

test.describe("the hero's play and enqueue buttons", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    /*
     * The round trip, which is the half no other layer sees. `queueTracks` is an OPTIONAL Inertia
     * prop: it is absent from the page until one of these buttons asks for it by name, and only a
     * browser can show that the ask really lands and really fills the queue.
     *
     * They were items in a popover until 2026-08-11 (SubjectMenu → SubjectActions), which changed
     * nothing about what is tested here: the fetch and the two verbs are the same code, now shared
     * with the playlist page's menu through `useSubjectTracks`.
     *
     * The seeded artist is used rather than a song, because the artist is the case that matters:
     * their songs table is paginated, so "play artist" has to queue more than the rows on screen.
     */

    /** Open an artist's page. Returns the artist's own song count, read from the hero's facts. */
    const openArtist = async (page: Page): Promise<number> => {
        await page.goto("/music/artists");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);

        const songs = await page.locator(".fact-pair", { hasText: "Songs" }).innerText();

        return Number(songs.replace(/\D/gu, ""));
    };

    test("starts playing from an EMPTY queue, where there is no element yet", async ({ page }) => {
        /*
         * The case the spec below missed and a listener hit immediately: with nothing queued
         * there is no player bar, so no <audio> element, so `play()` arrives a tick before the
         * thing it needs. It used to fill the queue and sit paused. Pressing from an empty queue
         * is also the ordinary way to use this — you open an artist and press play.
         */
        const songs = await openArtist(page);
        await page.locator(".subject-actions__play").click();

        await expect(page.locator(".play-queue__row")).toHaveCount(songs, { timeout: 15_000 });
        await expect
            .poll(() => page.evaluate(() => (document.querySelector("audio") as HTMLAudioElement).paused), {
                timeout: 10_000
            })
            .toBe(false);
        // The transport agrees, which is what a reader actually looks at.
        await expect(page.locator(".player-bar__control--play")).toHaveAttribute("aria-label", "Pause");
    });

    test("plays the whole artist, replacing whatever was queued", async ({ page }) => {
        // Something already in the queue, so "replace" is observable rather than assumed.
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        const dropped = await pageHeading(page).innerText();
        await enqueueFromHero(page);
        await expect(page.locator(".play-queue__row")).toHaveCount(1);

        const songs = await openArtist(page);
        await page.locator(".subject-actions__play").click();

        // Every track of theirs, and not the 25 the table shows.
        await expect(page.locator(".play-queue__row")).toHaveCount(songs, { timeout: 15_000 });
        await expect(page.locator(".play-queue__name").filter({ hasText: dropped })).toHaveCount(0);
        // Playing, because "play" means play — the click is the gesture a browser requires.
        await expect
            .poll(() => page.evaluate(() => (document.querySelector("audio") as HTMLAudioElement).paused), {
                timeout: 10_000
            })
            .toBe(false);
    });

    test("appends the whole artist on enqueue, keeping what is playing", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        const kept = await pageHeading(page).innerText();
        await enqueueFromHero(page);
        await expect(page.locator(".player-bar__name")).toHaveText(kept);

        const songs = await openArtist(page);
        await page.locator(".subject-actions__enqueue").click();

        await expect(page.locator(".play-queue__row")).toHaveCount(songs + 1, { timeout: 15_000 });
        // The loaded track is untouched — enqueue must not steal the player.
        await expect(page.locator(".player-bar__name")).toHaveText(kept);
    });
});
