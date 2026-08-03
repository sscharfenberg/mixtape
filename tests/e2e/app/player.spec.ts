import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * PLAYBACK — the part that only a real browser can answer.
 *
 * Everything about the player's decisions is covered in Vitest against happy-dom: the
 * intent/state split at a track boundary, the repeat wrap, the buffered ranges becoming
 * geometry, seeking being committed on release. What none of that can tell us is whether
 * the whole thing makes sound: happy-dom has no decoder, no network stack behind an
 * <audio src>, and no HTTP Range. Four things therefore live here and nowhere else.
 *
 *   - The stream route really serves audio to a real media element, under the app's own
 *     CSP. `media-src 'self'` is the reason the vidstack decision mattered.
 *   - The queue really ADVANCES BY ITSELF when a track ends. This is the headline v2
 *     feature (background playback), the `ended` event is the only thing that drives it,
 *     and no fake can produce one honestly.
 *   - Repeat really wraps at the end of the queue.
 *   - The timeline really moves, and the buffer indicator really fills.
 *
 * THE FIXTURE'S AUDIO IS ONE SECOND LONG while the seeded rows claim two to eight
 * minutes (see seedMediaFiles in tests/e2e/support/environment.ts). That is deliberate —
 * a track that ends in a second makes the auto-advance assertion fast and
 * deterministic — but it has one consequence worth stating: the durations DISAGREE, so
 * nothing here asserts a position derived from the rail's width, and the seek test moves
 * the cursor to a time that is inside the real file. The geometry itself (a percentage
 * from a duration) is Vitest's job, where the numbers are whatever the test says.
 *
 * The specs are written so that no assertion races the end of the track: anything that
 * needs playback to still be running turns REPEAT on first, which makes a one-track queue
 * play forever.
 */

/** The <audio> element's own state, read out of the page. */
type AudioState = { paused: boolean; currentTime: number; src: string; buffered: number };

/** Read the real element rather than what the UI claims about it. */
const audioState = (page: Page): Promise<AudioState> =>
    page.evaluate(() => {
        const audio = document.querySelector("audio") as HTMLAudioElement;

        return {
            paused: audio.paused,
            currentTime: audio.currentTime,
            src: audio.getAttribute("src") ?? "",
            buffered: audio.buffered.length > 0 ? audio.buffered.end(audio.buffered.length - 1) : 0
        };
    });

/**
 * Queue `count` songs off the listing, one after another, and return their titles.
 *
 * Through the real UI rather than by seeding localStorage: the enqueue button is what a
 * listener presses, and the payload it builds (including the stream URL) is exactly what
 * these specs need to be sure of.
 */
const enqueueSongs = async (page: Page, count: number): Promise<string[]> => {
    const titles: string[] = [];

    for (let row = 0; row < count; row += 1) {
        await page.goto("/music/songs");
        await page.locator("tbody tr").nth(row).click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        titles.push(await page.locator("main h1").first().innerText());
        await page.locator(".hero-section__actions button").click();
    }

    await expect(page.locator(".player-bar")).toBeVisible();

    return titles;
};

/** The transport's play/pause button. */
const playButton = (page: Page) => page.locator(".player-bar__control--play");

/** Turn repeat on through the queue menu, so playback cannot run out mid-assertion. */
const enableRepeat = async (page: Page): Promise<void> => {
    await page.locator(".play-queue .popover-button").click();
    const repeat = page.locator(".play-queue .popover-list-item").first();
    await repeat.click();
    await expect(repeat).toHaveAttribute("aria-pressed", "true");
    // Close the popover again so it cannot cover the transport.
    await page.keyboard.press("Escape");
};

test.describe("the player", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("streams the queued track to a real audio element", async ({ page }) => {
        await enqueueSongs(page, 1);

        const state = await audioState(page);
        // Loaded but NOT playing: a page with a queue on it has had no user gesture yet,
        // and starting by itself is exactly what a browser is entitled to block.
        expect(state.src).toMatch(/^\/music\/songs\/[0-9a-f-]{36}\/stream$/u);
        expect(state.paused).toBe(true);
    });

    test("answers the stream request with audio, not a page", async ({ page }) => {
        // The route, the auth on it, and the Content-Type — end to end. A redirect to
        // /login here would present as an <audio> that simply never plays.
        const response = page.waitForResponse(res => res.url().includes("/stream"));

        await enqueueSongs(page, 1);
        await playButton(page).click();

        const streamed = await response;
        expect([200, 206]).toContain(streamed.status());
        expect(streamed.headers()["content-type"]).toBe("audio/mpeg");
    });

    test("plays, and moves the timeline while it does", async ({ page }) => {
        await enqueueSongs(page, 1);
        await enableRepeat(page);

        await playButton(page).click();

        // The element is really decoding, and the cursor the UI reads comes off it.
        await expect.poll(async () => (await audioState(page)).currentTime, { timeout: 5_000 }).toBeGreaterThan(0.1);
        expect((await audioState(page)).paused).toBe(false);
        await expect
            .poll(() => page.locator(".player-timeline__time").first().innerText())
            .not.toBe("0:00");
    });

    test("swaps the glyph and the label for pause while playing", async ({ page }) => {
        await enqueueSongs(page, 1);
        await enableRepeat(page);

        await playButton(page).click();

        // Icon writes `xlink:href`, which a real browser will not hand back under the plain
        // `href` name the way happy-dom does — hence the qualified read.
        await expect
            .poll(() => playButton(page).locator("use").evaluate(el => el.getAttribute("xlink:href")))
            .toBe("#pause");
        await expect(playButton(page)).toHaveAttribute("aria-label", "Pause");
    });

    test("pauses where it is, and resumes from there", async ({ page }) => {
        await enqueueSongs(page, 1);
        await enableRepeat(page);

        await playButton(page).click();
        await expect.poll(async () => (await audioState(page)).currentTime, { timeout: 5_000 }).toBeGreaterThan(0.1);
        await playButton(page).click();

        const paused = await audioState(page);
        expect(paused.paused).toBe(true);
        expect(paused.currentTime).toBeGreaterThan(0);

        await playButton(page).click();
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
    });

    test("fills the buffer indicator with what it has downloaded", async ({ page }) => {
        // The owner's addition to the plan, and the one thing the UI can say about the
        // NETWORK: how much of this track would a drag ahead cost nothing to reach.
        await enqueueSongs(page, 1);
        await enableRepeat(page);

        await playButton(page).click();
        await expect.poll(async () => (await audioState(page)).buffered, { timeout: 5_000 }).toBeGreaterThan(0);

        const segment = page.locator(".player-timeline__buffer").first();
        await expect(segment).toHaveCount(1);
        // A width in pixels, from a percentage of a rail — the whole chain from the
        // element's TimeRanges to something on screen.
        expect((await segment.boundingBox())!.width).toBeGreaterThan(0);
    });

    test("seeks the element when the rail is scrubbed", async ({ page }) => {
        await enqueueSongs(page, 1);

        // Driven as the browser drives it — value, then `input`, then `change` — rather
        // than by clicking a coordinate on a 6px rail, which would be a test about
        // arithmetic on a bounding box. 0.5s is inside the fixture's real audio; see the
        // note at the top about the durations disagreeing on purpose.
        await page.locator(".player-timeline__input").evaluate(element => {
            const input = element as HTMLInputElement;
            input.value = "0.5";
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.dispatchEvent(new Event("change", { bubbles: true }));
        });

        expect((await audioState(page)).currentTime).toBeCloseTo(0.5, 1);
    });

    test("advances to the next track by itself when one ends", async ({ page }) => {
        /*
         * THE test in this file. Auto-advance rides the `ended` event and nothing else —
         * no timer, because a hidden tab throttles those to about once a minute — so this
         * is the assertion that the headline background-playback feature works at all.
         * Nothing is clicked between the two tracks.
         */
        const [first, second] = await enqueueSongs(page, 2);
        expect(second).not.toBe(first);

        await expect(page.locator(".player-bar__name")).toHaveText(first);
        await playButton(page).click();

        await expect(page.locator(".player-bar__name")).toHaveText(second, { timeout: 10_000 });
        // Still playing, which is the half a naive implementation loses: browsers fire
        // `pause` immediately before `ended`.
        expect((await audioState(page)).paused).toBe(false);
        expect((await audioState(page)).src).toContain("/stream");
    });

    test("stops at the end of the queue with repeat off", async ({ page }) => {
        const [only] = await enqueueSongs(page, 1);

        await playButton(page).click();
        await expect
            .poll(async () => (await audioState(page)).paused, { timeout: 10_000 })
            .toBe(true);

        // The bar stays, on the last track, offering to play it again — it must not
        // disappear just because the music stopped.
        await expect(page.locator(".player-bar__name")).toHaveText(only);
        await expect(playButton(page).locator("use")).toHaveAttribute("xlink:href", "#play");
    });

    test("wraps back to the first track with repeat on", async ({ page }) => {
        const [first, second] = await enqueueSongs(page, 2);
        await enableRepeat(page);
        await page.locator(".play-queue__load").nth(1).click();
        await expect(page.locator(".player-bar__name")).toHaveText(second);

        // Already playing — the row click is a gesture, and the panel's label says "play".
        await expect(page.locator(".player-bar__name")).toHaveText(first, { timeout: 10_000 });
        expect((await audioState(page)).paused).toBe(false);
    });

    test("plays the track when the row BODY is clicked, not just the cover", async ({ page }) => {
        /*
         * The play button wraps only the 24px cover; a stretched `::after` is what makes
         * the whole row the target. That is a HIT AREA, so this is the only layer that can
         * check it — happy-dom has no layout, and a Vitest click on the <li> would reach
         * the handler through the DOM whether the overlay existed or not, asserting nothing.
         *
         * The click lands in the row's left padding at mid-height: inside the row, outside
         * the cover button, and clear of the corners (a border-radius clips hit-testing).
         */
        const [, second] = await enqueueSongs(page, 2);
        await enableRepeat(page);

        const row = page.locator(".play-queue__row").nth(1);
        const box = (await row.boundingBox())!;
        await row.click({ position: { x: 3, y: box.height / 2 } });

        await expect(page.locator(".player-bar__name")).toHaveText(second);
        await expect.poll(async () => (await audioState(page)).paused, { timeout: 5_000 }).toBe(false);
    });

    test("plays from the title too, instead of navigating to the song", async ({ page }) => {
        /*
         * The title was a <Link>, which made the one word a listener aims at the one place
         * that left the page. It is plain text under the play overlay now, so BOTH halves
         * are worth asserting: that the track starts, and that the URL did not move. The
         * second is the part that would regress silently — re-introduce an anchor here and
         * playback still appears to work, because the click lands on the row on its way out.
         */
        const [, second] = await enqueueSongs(page, 2);
        await enableRepeat(page);
        await page.goto("/music/albums");
        const before = page.url();

        /*
         * `force` because Playwright's actionability check REFUSES this click otherwise:
         * `.play-queue__load` "intercepts pointer events" at the title's position. That
         * refusal is the overlay working — the title is supposed to be covered. Forcing
         * dispatches at the title's own coordinates and lets the browser hit-test, which
         * is exactly what a listener's click does; without it the test asserts nothing but
         * Playwright's opinion.
         */
        await page.locator(".play-queue__name").nth(1).click({ force: true });

        await expect(page.locator(".player-bar__name")).toHaveText(second);
        await expect.poll(async () => (await audioState(page)).paused, { timeout: 5_000 }).toBe(false);
        expect(page.url()).toBe(before);
    });

    test("keeps the remove button clickable above the play overlay", async ({ page }) => {
        /*
         * The remove button is now the ONLY thing lifted above the overlay, with a position
         * as well as a z-index — an absolutely positioned pseudo-element paints over every
         * non-positioned sibling regardless of DOM order. Drop that lift and the row plays
         * while nothing can be removed from it, which is what this catches.
         */
        await enqueueSongs(page, 2);

        await page.locator(".play-queue__remove").first().click();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
    });

    test("plays under the production Content-Security-Policy", async ({ page }) => {
        /*
         * The check `docs/self-hosting/04-going-public.md` left open, and the reason it was
         * open: the plan expected vidstack, which wraps audio in a MediaSource and would
         * have needed `media-src blob:` added to the live policy. A native <audio> pointed
         * at a same-origin route does not, and this is the proof rather than the argument.
         *
         * `artisan serve` sends no CSP — the live policy is an nginx `add_header` — so it is
         * injected onto the document response here, copied verbatim from
         * docs/self-hosting/files/mixtape.prod.nginx.conf. A violation would show up as a
         * console error AND as an element that never leaves `paused`, so both are asserted.
         */
        const CSP =
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; " +
            "img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; " +
            "base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests";

        await page.route("**/*", async route => {
            if (route.request().resourceType() !== "document") return route.fallback();

            const response = await route.fetch();
            await route.fulfill({
                response,
                headers: { ...response.headers(), "content-security-policy": CSP }
            });
        });

        const violations: string[] = [];
        page.on("console", message => {
            if (message.type() === "error" && /content security policy/iu.test(message.text())) {
                violations.push(message.text());
            }
        });

        await enqueueSongs(page, 1);
        await playButton(page).click();

        await expect.poll(async () => (await audioState(page)).currentTime, { timeout: 5_000 }).toBeGreaterThan(0.1);
        expect(violations).toStrictEqual([]);
    });

    test("keeps playing across an Inertia navigation", async ({ page }) => {
        // The reason the player lives in the layout rather than in a page. A page-level
        // <audio> would be torn down and rebuilt on every click, and the music would stop
        // every time somebody opened an album.
        await enqueueSongs(page, 1);
        await enableRepeat(page);
        await playButton(page).click();
        await expect.poll(async () => (await audioState(page)).currentTime, { timeout: 5_000 }).toBeGreaterThan(0.1);

        await page.locator(".player-bar__name").click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        expect((await audioState(page)).paused).toBe(false);
    });
});
