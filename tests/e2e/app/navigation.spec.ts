import { expect, test } from "@playwright/test";

/*
 * How a navigation FEELS, which is the one thing only a real browser can answer.
 *
 * Three separate mechanisms conspire to make a click read as "you are just there", and each
 * of them is invisible to every other layer of the suite:
 *
 *   - the breadcrumb travels as an Inertia layout prop, which Inertia resets at the component
 *     swap. It used to be a module ref we cleared on the `start` event, which emptied it the
 *     instant a link was clicked — the <nav> unmounted, the page jumped up, and jumped back
 *     when the new page declared its own. Sampling every frame is the only way to catch a
 *     regression to that: the end state looks identical either way.
 *   - the swap runs inside document.startViewTransition, so the outgoing page dissolves into
 *     the incoming one instead of being replaced in a single frame.
 *   - links and DataTable rows prefetch on hover, so the response is usually already in hand
 *     by the time the click lands.
 *
 * Motion is opt-in in this app (CLAUDE.md → Motion), so `reducedMotion` is pinned per test
 * rather than inherited from whatever the machine running the suite happens to prefer.
 */

/** What each animation frame is counted for. */
type Frame = { crumbs: number; overlay: number; bar: number };

/** Count what is on screen every animation frame, and count view transitions. */
const instrument = (page: import("@playwright/test").Page) =>
    page.addInitScript(() => {
        const w = window as unknown as { __vt: number; __frames: Record<string, number>[] };
        w.__vt = 0;
        const original = document.startViewTransition?.bind(document);
        if (original) {
            document.startViewTransition = (callback: never) => {
                w.__vt++;

                return original(callback);
            };
        }
        w.__frames = [];
        const sample = () => {
            w.__frames.push({
                crumbs: document.querySelectorAll("nav.breadcrumb").length,
                overlay: document.querySelectorAll(".dt__overlay").length,
                bar: document.querySelectorAll(".progressbar").length
            });
            requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
    });

/** Read the instrumentation back, and reset it so a sample covers one interaction only. */
const samples = async (page: import("@playwright/test").Page) => {
    const taken = await page.evaluate(() => {
        const w = window as unknown as { __vt: number; __frames: Frame[] };
        const snapshot = { vt: w.__vt, frames: [...w.__frames] };
        w.__vt = 0;
        w.__frames.length = 0;

        return snapshot;
    });

    /** The highest count `key` reached across the sampled frames. */
    const peak = (key: keyof Frame): number => Math.max(...taken.frames.map(frame => frame[key]));
    /** The lowest count `key` fell to across the sampled frames. */
    const trough = (key: keyof Frame): number => Math.min(...taken.frames.map(frame => frame[key]));

    return { ...taken, peak, trough };
};

test.describe("navigating between pages", () => {
    // Through `contextOptions` rather than as a bare option: this Playwright version keeps
    // `reducedMotion` on BrowserContextOptions. The project's own top-level `storageState`
    // still applies, so these stay signed in.
    test.use({ contextOptions: { reducedMotion: "no-preference" } });

    test("never drops the breadcrumb while a visit is in flight", async ({ page }) => {
        await instrument(page);
        await page.goto("/music/songs");
        await expect(page.locator("nav.breadcrumb")).toBeVisible();
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await samples(page); // discard the initial load
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await expect(page.locator("nav.breadcrumb")).toBeVisible();

        const { frames, trough } = await samples(page);
        // Every sampled frame had exactly one trail on screen — the outgoing page's, then
        // the incoming one's, with no frame in between showing none.
        expect(frames.length).toBeGreaterThan(5);
        expect(trough("crumbs")).toBe(1);
    });

    test("cross-fades the page swap instead of replacing it in one frame", async ({ page }) => {
        await instrument(page);
        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await samples(page);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        const { vt } = await samples(page);
        expect(vt).toBe(1);
    });

    test("warms a row's page while the pointer rests on it", async ({ page }) => {
        // A row navigates through router.visit rather than a <Link>, so it gets none of
        // Inertia's built-in link prefetching — DataTableBody does it by hand.
        const prefetched: string[] = [];
        page.on("request", request => {
            if (request.headers()["purpose"] === "prefetch") prefetched.push(request.url());
        });

        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await page.locator("tbody tr").first().hover();

        await expect.poll(() => prefetched).toHaveLength(1);
        expect(prefetched[0]).toMatch(/\/music\/songs\/[0-9a-f-]{36}/u);
    });

    test("shows no loading chrome at all while merely hovering", async ({ page }) => {
        /*
         * The bug the prefetching shipped with. Inertia fires the same `start` / `finish`
         * events for a prefetch as for a real visit, so the table's overlay and the page's
         * progress bar both went up for a page nobody had asked for — running the pointer
         * across the table was enough to make it flash.
         *
         * The response is held back deliberately: the progress bar waits 250ms before it
         * appears, so against a local server answering in 20ms this would pass whether the
         * guard existed or not.
         */
        await page.route(/\/music\/songs\/[0-9a-f-]{36}/u, async route => {
            await new Promise(resolve => setTimeout(resolve, 700));

            return route.continue();
        });
        await instrument(page);
        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await samples(page);
        await page.locator("tbody tr").first().hover();
        await page.waitForTimeout(1000);

        const { frames, peak } = await samples(page);
        expect(frames.length).toBeGreaterThan(5);
        expect(peak("overlay")).toBe(0);
        expect(peak("bar")).toBe(0);
    });
});

test.describe("the breadcrumb on a narrow screen", () => {
    /*
     * Below `landscape` the whole trail collapses to ONE chip — the parent — because at that
     * width its only job is "go back one level". Which chip that is, and whether any survives at
     * all, is a `display: none` decided by a media query: only a real viewport can answer it, and
     * the collapse had been wrong on every page directly under the root since it was written.
     */
    test.use({ viewport: { width: 390, height: 844 } });

    test("offers home as the way back from a page directly under the root", async ({ page }) => {
        // `/music` declares one crumb — itself — so there is no second-to-last crumb to mark, and
        // the trail used to collapse to nothing: an empty <nav> holding its own margin.
        await page.goto("/music");

        const shown = page.locator(".breadcrumb__item:visible");
        await expect(shown).toHaveCount(1);
        await expect(shown).toHaveAttribute("href", "/");
        await expect(shown).toHaveClass(/breadcrumb__item--parent/u);

        // NAMED at this width. A lone house glyph on a back-pointing arrow is a rebus; the icon
        // carries it on the desktop ribbon, where the word would cost the crumb that holds real
        // information its room.
        await expect(shown.locator(".breadcrumb__label--home")).toBeVisible();
    });

    test("wears the arrow rather than a rectangle when hovered", async ({ page }) => {
        /*
         * The chip is drawn as two skewed pseudo-halves; home ALSO paints a fill on its inner
         * span, to square off the ribbon's left end. That fill is undone at this width or it
         * covers the arrowhead — and the `:hover` rule repaints exactly the same rectangle at a
         * higher specificity, which is how a fixed chip went back to being a box under a finger.
         */
        await page.goto("/music");
        const home = page.locator(".breadcrumb__item:visible");
        await home.hover();

        const painted = await home.locator("> span").evaluate(span => {
            const style = getComputedStyle(span);

            return { background: style.backgroundColor, shadow: style.boxShadow };
        });

        // Transparent and unshadowed: whatever the hover shows is the skewed halves, not this box.
        expect(painted.background).toBe("rgba(0, 0, 0, 0)");
        expect(painted.shadow).toBe("none");
    });

    test("offers the parent crumb, not home, once the trail has one", async ({ page }) => {
        await page.goto("/music/songs");

        const shown = page.locator(".breadcrumb__item:visible");
        await expect(shown).toHaveCount(1);
        await expect(shown).toHaveAttribute("href", "/music");
    });

    test("leaves home an icon on the full ribbon", async ({ page }) => {
        // The other half of the rule: above `landscape` the whole trail is on screen, the icon is
        // unambiguous as its first chip, and a word there would push a song title into ellipsis.
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto("/music/songs");

        await expect(page.locator(".breadcrumb__label--home")).toBeHidden();
        await expect(page.locator(".breadcrumb__item:visible")).toHaveCount(3);
    });

    test("really goes back when pressed", async ({ page }) => {
        await page.goto("/music");
        await page.locator(".breadcrumb__item:visible").click();

        await page.waitForURL(/\/$/u);
    });
});

test.describe("navigating with reduced motion", () => {
    test.use({ contextOptions: { reducedMotion: "reduce" } });

    test("declines the cross-fade for a reader who asked for less motion", async ({ page }) => {
        // The guard lives in main.ts, not in CSS: the fade is generated by the browser, so
        // there is no rule of ours to put behind a media query.
        await instrument(page);
        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();

        await samples(page);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        const { vt } = await samples(page);
        expect(vt).toBe(0);
    });
});
