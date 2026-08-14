import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { columnValues, pageHeading } from "../support/actions";

/*
 * Getting around the library: the four listings, the detail pages they lead to, and the
 * breadcrumb trail back out.
 *
 * The library is a FIXED fixture (database/seeders/E2ESeeder.php), so these specs name real
 * albums and songs.
 *
 * The point of testing navigation here rather than in a unit test is that these are real
 * Inertia visits. A page's props, its breadcrumb declaration and its rendered heading all
 * have to agree AFTER a client-side navigation — including the case that has bitten this
 * app before, where a component instance is reused for a different subject and keeps
 * something from the last one (see the CoverImage reset, and the Discography page reset).
 */

test.describe("browsing the library", () => {
    test("reaches all four listings from the music page", async ({ page }) => {
        await page.goto("/music");

        for (const path of ["/music/songs", "/music/albums", "/music/artists", "/music/genres"]) {
            await page.goto(path);
            await expect(page.locator("tbody tr").first()).toBeVisible();
        }
    });

    test("walks from the songs listing into a song and back out via the breadcrumb", async ({ page }) => {
        await page.goto("/music/songs");
        const [title] = await columnValues(page, "Titel");

        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(title);

        // The trail's last crumb is the song itself and carries no link; the one before
        // it goes back to the listing.
        await page.locator(".breadcrumb").getByRole("link", { name: /Songs/u }).click();

        await page.waitForURL(/\/music\/songs$/u);
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    test("shows the song's own facts, formatted rather than raw", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        // A clock, not a number of seconds; a locale-formatted size, not a byte count.
        // These are the client-side formatters running against real server data.
        await expect(page.getByText(/^\d{1,2}:\d{2}(:\d{2})?$/u).first()).toBeVisible();
        await expect(page.getByText(/\d+,\d{2}\s(MB|GB)/u).first()).toBeVisible();
    });

    test("opens an artist and lists their records", async ({ page }) => {
        await page.goto("/music/artists");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        const [name] = await columnValues(page, /Künstler|Name/u);

        await page.locator("tbody tr").first().click();

        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(name);
    });

    test("opens an album and lists its tracks", async ({ page }) => {
        // The albums table leads with a cover cell, so the name is not column one.
        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        const [name] = await columnValues(page, "Album");

        await page.locator("tbody tr").first().click();

        await page.waitForURL(/\/music\/albums\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(name);
    });

    test("carries the right subject from one detail page to the next", async ({ page }) => {
        /*
         * The regression this guards: Vue reuses a mounted page component across an
         * Inertia visit to the same route with different params, so anything held in
         * local state must reset. Two different artists in a row is the cheapest way to
         * catch a page that kept the first one's data.
         */
        await page.goto("/music/artists");
        const rows = page.locator("tbody tr");
        await expect(rows.first()).toBeVisible();
        const names = await columnValues(page, /Künstler|Name/u);

        await rows.first().click();
        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(pageHeading(page)).toHaveText(names[0]);
        const firstUrl = page.url();

        await page.goBack();
        await expect(rows.first()).toBeVisible();
        await rows.nth(1).click();

        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        expect(page.url()).not.toBe(firstUrl);
        await expect(pageHeading(page)).toHaveText(names[1]);
    });

    test("renders cover art or its placeholder, never a broken image", async ({ page }) => {
        await page.goto("/music/albums");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/albums\/[0-9a-f-]{36}/u);
        // Let the covers finish resolving — an image still in flight is not a broken one.
        await page.waitForLoadState("networkidle");

        /*
         * Only VISIBLE images count. Two legitimate reasons an <img> here can report
         * itself unloaded: covers are `loading="lazy"`, so one below the fold is never
         * fetched, and the discography renders its row and card artwork together with one
         * of them display:none — a hidden lazy image is deliberately never requested.
         * Neither is a broken picture; what would be is a VISIBLE image that failed.
         *
         * The seeded library points at cover files that do not exist, so this is also a
         * live exercise of CoverImage's 404 fallback: those images must have been swapped
         * for the placeholder rather than left broken on the page.
         */
        const brokenVisible = await page.locator("img").evaluateAll(images =>
            images.filter(node => {
                const image = node as HTMLImageElement;
                const isVisible = image.getClientRects().length > 0;

                return isVisible && (!image.complete || image.naturalWidth === 0);
            }).length
        );

        expect(brokenVisible).toBe(0);
    });

    test("drops a sparsely-tagged song's missing facts instead of rendering them empty", async ({ page }) => {
        /*
         * The fixture seeds exactly one track with no duration, composer, publisher or bit
         * rate — the untagged rip. Its page must simply not show those rows: the failure
         * mode being guarded is a card full of "0:00", "null" and empty labels, which is
         * what every one of those fields renders as if the guard is dropped.
         */
        await page.goto("/music/songs");
        await page.getByRole("searchbox").fill("Fitter Happier");
        await page.waitForURL(/search=/u);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        await expect(pageHeading(page)).toHaveText("Fitter Happier");

        /*
         * Asserted on the LABELS of the rows that should be absent, rather than on their
         * would-be values. Searching the page text for "0:00" looks equivalent and is not:
         * it also matches the "09:00:00" in the added-at timestamp, so the test fails on a
         * page that is behaving perfectly.
         */
        const facts = page.locator(".fact-pair");
        await expect(facts.filter({ hasText: "Dauer" })).toHaveCount(0);
        await expect(facts.filter({ hasText: "Komponist" })).toHaveCount(0);
        await expect(facts.filter({ hasText: "Label" })).toHaveCount(0);
        await expect(facts.filter({ hasText: "Bitrate" })).toHaveCount(0);

        const body = await page.locator("main").innerText();
        expect(body).not.toContain("null");
        expect(body).not.toContain("undefined");
        // The facts it DOES carry are still there, so this is not passing by rendering nothing.
        expect(body).toContain("OK Computer");
        await expect(facts.filter({ hasText: "Track" })).not.toHaveCount(0);
    });

    test("reports a missing song as a 404 rather than an error page", async ({ page }) => {
        const response = await page.goto("/music/songs/11111111-1111-4111-8111-111111111111");

        expect(response?.status()).toBe(404);
    });
});

test.describe("the document outline", () => {
    /*
     * ONE <h1> PER PAGE, and it is the wordmark in the header.
     *
     * The easy way to get two is a hero passing an `<h1>` for its subject's name while the
     * header's wordmark — which every page renders — is already one. Every page title is an
     * `<h2>`, and this is what keeps it that way.
     *
     * IT IS ALSO WHAT `pageHeading` STANDS ON: that helper matches the first `<h2>` inside
     * <main>, which is only "the page's own title" for as long as the wordmark remains the
     * document's sole `<h1>`.
     *
     * Detail pages are reached by clicking a row rather than by hardcoding a seeded id, so the
     * fixture stays free to change ids.
     */
    const listings = [
        ["/music/songs", "a song"],
        ["/music/albums", "an album"],
        ["/music/artists", "an artist"],
        ["/music/genres", "a genre"]
    ] as const;

    /** Every `<h1>` on the page, and whether the header owns it. */
    const headings = (page: Page) =>
        page.evaluate(() => {
            const all = [...document.querySelectorAll("h1")];

            return {
                count: all.length,
                inHeader: all.every(node => node.closest("header") !== null),
                text: all.map(node => node.textContent?.trim() ?? "")
            };
        });

    for (const [path, subject] of listings) {
        test(`keeps one h1 on ${path} and on ${subject}`, async ({ page }) => {
            await page.goto(path);
            await expect(page.locator("tbody tr").first()).toBeVisible();

            const listing = await headings(page);
            expect(listing.count).toBe(1);
            expect(listing.inHeader).toBe(true);

            await page.locator("tbody tr").first().click();
            await page.waitForURL(/\/[0-9a-f-]{36}$/u);
            await expect(pageHeading(page)).toBeVisible();

            const detail = await headings(page);
            expect(detail.count).toBe(1);
            expect(detail.inHeader).toBe(true);
            // The page's own title is still a heading, one level down — in the <Headline>
            // above the hero, which is what `pageHeading` finds.
            await expect(pageHeading(page)).toHaveJSProperty("tagName", "H2");
        });
    }

    test("keeps one h1 on the pages without a hero", async ({ page }) => {
        for (const path of ["/", "/dashboard"]) {
            await page.goto(path);
            // Waited for, not assumed: `goto` resolves on load, while the header is mounted by
            // Vue afterwards — counting straight away read zero headings on a cold worker and
            // passed only because a warm one happened to be quicker.
            await expect(page.locator("header h1")).toBeVisible();

            const outline = await headings(page);

            expect(outline.count, `on ${path}`).toBe(1);
            expect(outline.inHeader, `on ${path}`).toBe(true);
        }
    });
});

test.describe("the detail hero", () => {
    /*
     * The hero's TRAILING EDGE, which is geometry and therefore only answerable here.
     *
     * `openFirstRow` clicks rather than hardcoding a seeded id, like the outline specs above,
     * so the fixture stays free to change ids.
     */
    const openFirstRow = async (page: Page, listing: string): Promise<void> => {
        await page.setViewportSize({ width: 1400, height: 1000 });
        await page.goto(listing);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/[0-9a-f-]{36}$/u);
        await expect(page.locator(".hero-section")).toBeVisible();
    };

    /** How much panel is left over past the text column's trailing edge. */
    const slack = async (page: Page): Promise<number> => {
        const hero = (await page.locator(".hero-section").boundingBox())!;
        const meta = (await page.locator(".hero-section__meta").boundingBox())!;

        return hero.x + hero.width - (meta.x + meta.width);
    };

    test("leaves no phantom column on a hero with no cover", async ({ page }) => {
        /*
         * A genre has no artwork of any kind, so it slots nothing into `#cover` — and a grid that
         * declares its second column anyway resolves that track to zero width while the COLUMN GAP
         * between the two does not go away. The panel then carries a stripe of dead space inside
         * its trailing padding: a stray margin nobody wrote, and invisible to every assertion that
         * only reads the DOM.
         *
         * What is left over past the text column must therefore be the padding and nothing else.
         * Compared against the panel's OWN padding rather than a literal, so the number cannot go
         * stale when that token moves.
         */
        await openFirstRow(page, "/music/genres");

        const padding = await page
            .locator(".hero-section")
            .evaluate(node => parseFloat(getComputedStyle(node).paddingRight));

        expect(await slack(page)).toBeCloseTo(padding, 0);
    });

    test("fans an artist's own sleeves where a photograph would be", async ({ page }) => {
        // MixTape stores no artist images, so the hero shows a few of their records instead.
        // At least one, never more than three, and hard against the trailing padding.
        await openFirstRow(page, "/music/artists");

        const sleeves = page.locator(".cover-sleeves__sleeve");
        expect(await sleeves.count()).toBeGreaterThan(0);
        expect(await sleeves.count()).toBeLessThanOrEqual(3);

        const hero = (await page.locator(".hero-section").boundingBox())!;
        const fan = (await page.locator(".cover-sleeves").boundingBox())!;
        const padding = await page
            .locator(".hero-section")
            .evaluate(node => parseFloat(getComputedStyle(node).paddingRight));

        expect(hero.x + hero.width - (fan.x + fan.width)).toBeCloseTo(padding, 0);
    });
});

test.describe("a page heading too long for its line", () => {
    /*
     * THE ICON STAYS BESIDE THE TITLE, however many lines the title takes.
     *
     * Only a browser can answer this, and only at a width where the title genuinely does not
     * fit: the heading is a WRAPPING flex row, and flex collects items into lines by their
     * max-content size — so an unwrapped title is an anonymous flex item that gets pushed onto
     * a line of its own BEFORE it is ever given the chance to shrink and wrap inside one. The
     * icon was then left sitting alone above it. Reported on a song whose name runs to four
     * slash-separated clauses; fixed by holding the whole default slot in one
     * flex item with a zero basis (Headline).
     *
     * happy-dom cannot see any of it — there is no layout there, so a unit test asserting
     * where the icon SITS would assert nothing. What Vitest holds instead is the structure the
     * CSS rests on (Headline.test.ts); this holds the consequence.
     *
     * NARROWED AFTER ARRIVING, not before: the fixture's longest name is a 59-character
     * collaboration credit, which fits on one line at desktop width and would prove nothing
     * there — but the DataTable that leads to the page swaps its rows for CARDS below
     * `landscape`, so a spec that starts narrow has no `tbody tr` to click.
     */
    test("keeps the icon beside a title that wraps, not above it", async ({ page }) => {
        await page.goto("/music/artists");
        await page.getByRole("searchbox").fill("Jóhann");
        await page.waitForURL(/search=/u);
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/artists\/[0-9a-f-]{36}/u);
        await expect(page.locator(".hero-section")).toBeVisible();

        await page.setViewportSize({ width: 600, height: 900 });

        const geometry = await page.evaluate(() => {
            const content = document.querySelector("main h2 .headline__content")!;
            const icon = content.querySelector("svg.icon")!.getBoundingClientRect();

            // The title is a bare text node, so it has no box of its own — a Range does, and
            // one client rect PER LINE, which is also how we know it really wrapped.
            const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
            let node = walker.nextNode();
            while (node && !node.textContent?.trim()) node = walker.nextNode();

            const range = document.createRange();
            range.selectNodeContents(node!);
            const lines = [...range.getClientRects()];

            return {
                lineCount: lines.length,
                // BESIDE, not above: the icon's vertical span has to meet the block of text
                // somewhere. Not "meets the FIRST line", which is a different claim and not
                // the one being made — the icon is centred against however many lines there
                // are, so on a three-line title it sits level with the second.
                beside: icon.bottom > lines[0].top && icon.top < lines[lines.length - 1].bottom,
                // …and it leads the text rather than sitting anywhere among it.
                leading: lines.every(line => icon.right <= line.left + 1)
            };
        });

        // The precondition: without a title that actually wraps, the rest asserts nothing.
        expect(geometry.lineCount).toBeGreaterThan(1);
        expect(geometry.beside).toBe(true);
        expect(geometry.leading).toBe(true);
    });
});
