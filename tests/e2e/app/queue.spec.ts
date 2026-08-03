import { expect, test } from "@playwright/test";

/*
 * The play queue's LAYOUT, which is the half no other test layer can see.
 *
 * Three things here need a real browser and a real viewport:
 *
 *   - the footer and the player bar are alternatives. Vitest can prove PlayerBar renders
 *     when a track is loaded, but not that the footer left with it.
 *   - the panel takes 240px out of <main>, and the DataTable's table-or-cards switch is a
 *     CONTAINER query on that remaining width. Whether a listing survives with the queue
 *     open is a question about layout, and happy-dom has none. This is the specific
 *     regression that made the breakpoint move from `desktop` to `landscape`.
 *   - the panel has two entirely different shapes — a column beside the page, and a bottom
 *     sheet over it — chosen by viewport width.
 *
 * The queue also has to survive an Inertia navigation, which is the reason it and the
 * player live in the layout rather than in a page.
 */

/** Open a song's page and put it in the queue. Returns the song's title. */
const enqueueFirstSong = async (page: import("@playwright/test").Page): Promise<string> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    const title = await page.locator("main h1").first().innerText();
    await page.locator(".hero-section__actions button").click();
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
         * The reason the DataTable's container breakpoint moved to `landscape`. At 1440px
         * the panel leaves <main> about 1170px; under the old `desktop` (1024px) line that
         * was fine, but the margin was thin enough that a 1280px laptop tipped over it and
         * every listing in the app turned into cards the moment anything was queued.
         */
        await enqueueFirstSong(page);

        await page.goto("/music/songs");
        await expect(page.locator("table")).toBeVisible();
        await expect(page.locator(".dt-cards")).toBeHidden();
    });

    test("widens at `full`, and the content inset widens with it", async ({ page }) => {
        /*
         * This describe runs at 1440px, which IS the `full` line — so the panel is 360px
         * here and 240px below it (asserted at 420px in the narrow-screen block).
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

test.describe("the play queue on a narrow screen", () => {
    test.use({ viewport: { width: 420, height: 850 } });

    /** Queue a song from its page. At this width the listing is a card grid, not a table. */
    const enqueueFromCard = async (page: import("@playwright/test").Page): Promise<void> => {
        await page.goto("/music/songs");
        await page.locator(".dt-cards a").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await page.locator(".hero-section__actions button").click();
    };

    test("keeps the panel shut until the header's toggle opens it", async ({ page }) => {
        /*
         * The whole point of the narrow layout: 240px of panel on a 420px screen is
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
        expect(Math.round(panel.width)).toBe(240);
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

test.describe("the play queue from landscape up", () => {
    test.use({ viewport: { width: 900, height: 850 } });

    test("is simply there, with no toggle to press", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await page.locator(".hero-section__actions button").click();

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
