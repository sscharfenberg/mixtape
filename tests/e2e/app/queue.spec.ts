import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { stopQueueSync } from "../support/actions";
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

test.beforeEach(() => {
    clearServerQueue("queue");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`,
// so that request can outlive the test and land after the NEXT one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/**
 * Enqueue the subject of the page currently open, through the hero menu.
 *
 * The lone "enqueue" Button in the hero's #actions is gone (2026-08-06): the SubjectMenu in
 * the heading offers both verbs, so a button offering one was redundant. Every spec that used
 * to press it now opens the menu and picks the second item — which is also the path a reader
 * takes. Scoped to `.hero-section__menu`, because the site menu, the user menu, the queue menu
 * and the player settings all use `.popover-list-item` too.
 */
const enqueueFromHero = async (page: Page): Promise<void> => {
    // WAITED FOR, because enqueuing from the hero is asynchronous where the old button was
    // not: the menu asks the server for the subject's tracks (an optional Inertia prop), so
    // the queue grows a round trip after the click. Without this a caller reads the queue —
    // or the transport's disabled states — before the tracks have landed.
    const before = await page.locator(".play-queue__row").count();

    await page.locator(".hero-section__menu .popover-button").click();
    await page.locator(".hero-section__menu .popover-list-item").nth(1).click();
    await expect(page.locator(".play-queue__row")).toHaveCount(before + 1);
};

/** Open a song's page and put it in the queue. Returns the song's title. */
const enqueueFirstSong = async (page: import("@playwright/test").Page): Promise<string> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    const title = await page.locator(".hero-section__title").first().innerText();
    await enqueueFromHero(page);
    await expect(page.locator(".play-queue")).toBeVisible();

    return title;
};

test.describe("the play queue", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

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

    test("widens at `full`, and the content inset widens with it", async ({ page }) => {
        /*
         * This describe runs at 1440px, which IS the `full` line — so the panel is 360px
         * here and 280px below it (asserted at 420px in the narrow-screen block).
         *
         * The second assertion is the one worth having. The width lives in PlayQueue and
         * the room made for it lives in FullLayout's `--content-inset-end`; they are one
         * decision in two files, and if they drift the page's trailing column slides under
         * an opaque panel. Comparing <main>'s content edge against the panel's leading edge
         * is what catches that, and it can only be done in a real browser.
         */
        await enqueueFirstSong(page);
        await page.goto("/music/songs");

        const panel = (await page.locator(".play-queue").boundingBox())!;
        expect(Math.round(panel.width)).toBe(360);

        const clear = await page.evaluate(() => {
            const content = document.querySelector("main .container") ?? document.querySelector("main");
            const box = content!.getBoundingClientRect();
            const style = getComputedStyle(content!);

            return box.right - parseFloat(style.paddingRight);
        });

        // The content's inner edge stops at or before the panel starts — never under it.
        expect(clear).toBeLessThanOrEqual(panel.x);
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
        const title = await enqueueFirstSong(page);

        // The sync rides the queue's coalesced flush, so wait for the request itself rather
        // than guessing at the delay.
        await page.waitForResponse(
            response => response.url().includes("/player/state") && response.request().method() === "PUT"
        );

        await page.evaluate(() => window.localStorage.clear());
        await page.reload();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
        await expect(page.locator(".play-queue__row").first()).toContainText(title);
        // …and the bar is back with it, loaded and ready rather than merely listed.
        await expect(page.locator(".player-bar__name")).toContainText(title);
    });

    test("clears on the server too, so the other device does not restore it forever", async ({ page }) => {
        await enqueueFirstSong(page);
        await page.waitForResponse(
            response => response.url().includes("/player/state") && response.request().method() === "PUT"
        );

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

        expect(await glyph()).toBe("#playlist");
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
            titles.push(await page.locator(".hero-section__title").first().innerText());
            await enqueueFromHero(page);
        }
        await expect(page.locator(".play-queue__row")).toHaveCount(count);

        return titles;
    };

    /** The queue's titles, in the order the panel shows them. */
    const order = (page: import("@playwright/test").Page) => page.locator(".play-queue__name").allInnerTexts();

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
     * list, and ~420px leaves room for four or five rows. Width stays at `full` so the
     * panel is simply present without a toggle.
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

        // The same track eight times — duplicates are a normal queue, and it saves seven
        // page loads over queueing eight different songs.
        for (let i = 0; i < 7; i += 1) await enqueueFromHero(page);
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

    test("is simply there, with no toggle to press", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await enqueueFromHero(page);

        await expect(page.locator(".play-queue")).toBeVisible();
        // The button exists in the DOM but the media query hides it at this width.
        await expect(page.locator(".play-queue-toggle")).toBeHidden();
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

test.describe("the hero menu", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    /*
     * The round trip, which is the half no other layer sees. `queueTracks` is an OPTIONAL Inertia
     * prop: it is absent from the page until the menu asks for it by name, and only a browser can
     * show that the ask really lands and really fills the queue.
     *
     * The seeded artist is used rather than a song, because the artist is the case that matters:
     * their songs table is paginated, so "play artist" has to queue more than the rows on screen.
     */

    /** Open an artist page and its hero menu. Returns the artist's own song count from the facts. */
    const openArtistMenu = async (page: Page): Promise<number> => {
        await page.goto("/music/artists");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);

        const songs = await page.locator(".fact-pair", { hasText: "Songs" }).innerText();
        await page.locator(".hero-section__menu .popover-button").click();

        return Number(songs.replace(/\D/gu, ""));
    };

    test("starts playing from an EMPTY queue, where there is no element yet", async ({ page }) => {
        /*
         * The case the spec below missed and a listener hit immediately: with nothing queued
         * there is no player bar, so no <audio> element, so `play()` arrives a tick before the
         * thing it needs. It used to fill the queue and sit paused. Pressing from an empty queue
         * is also the ordinary way to use this — you open an artist and press play.
         */
        const songs = await openArtistMenu(page);
        await page.locator(".hero-section__menu .popover-list-item").first().click();

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
        const dropped = await page.locator(".hero-section__title").first().innerText();
        await enqueueFromHero(page);
        await expect(page.locator(".play-queue__row")).toHaveCount(1);

        const songs = await openArtistMenu(page);
        // Scoped to the hero: the page also carries the site menu, the user menu, the queue
        // menu and the player settings, all of which use `.popover-list-item`.
        await page.locator(".hero-section__menu .popover-list-item").first().click();

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
        const kept = await page.locator(".hero-section__title").first().innerText();
        await enqueueFromHero(page);
        await expect(page.locator(".player-bar__name")).toHaveText(kept);

        const songs = await openArtistMenu(page);
        await page.locator(".hero-section__menu .popover-list-item").nth(1).click();

        await expect(page.locator(".play-queue__row")).toHaveCount(songs + 1, { timeout: 15_000 });
        // The loaded track is untouched — enqueue must not steal the player.
        await expect(page.locator(".player-bar__name")).toHaveText(kept);
    });
});
