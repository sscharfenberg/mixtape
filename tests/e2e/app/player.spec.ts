import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { enqueueFromHero, openQueuePanel, pageHeading, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

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

/*
 * ITS OWN ACCOUNT, AND A CLEAN QUEUE PER TEST. The play queue is server state since the
 * `player_states` sync landed, so a fresh browser context is no longer a fresh player: a
 * queue follows the USER. Sharing one account across files let a spec in one worker restore
 * a queue another worker had just left, and sharing it across tests in this file let each
 * test inherit the last one's. The account is this file's alone (E2ESeeder seeds it,
 * auth.setup mints its session) and the reset below is what tests here owe each other.
 */
test.use({ storageState: specStorageState("player") });

/*
 * SEQUENTIAL, IN ONE WORKER, which the account above is worthless without. `fullyParallel`
 * parallelises at the TEST level, not the file level — so without this the tests in this
 * file run CONCURRENTLY against the one account they share, and each sees the others'
 * queues. That failed as a count one too high, on a different test every run.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("player");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`,
// so that request can outlive the test and land after the NEXT one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

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
        titles.push(await pageHeading(page).innerText());
        await enqueueFromHero(page);
    }

    await expect(page.locator(".player-bar")).toBeVisible();

    return titles;
};

/** The transport's play/pause button. */
const playButton = (page: Page) => page.locator(".player-bar__control--play");

/**
 * Turn repeat on through the player's settings popover, so playback cannot run out
 * mid-assertion.
 *
 * Through the real control rather than by seeding storage, for the reason the enqueue
 * helper gives: this is the path a listener takes. The radio is clipped to a pixel, so
 * the click lands on its LABEL — the whole visible option — which is also what a person
 * clicks.
 */
const setMode = async (page: Page, group: "playerMode" | "playerRepeat", value: "off" | "on"): Promise<void> => {
    await page.locator(".player-settings .popover-button").click();
    await page.locator(`label[for="${group}-${value}"]`).click();
    await expect(page.locator(`#${group}-${value}`)).toBeChecked();
    // Close the popover again so it cannot cover the transport.
    await page.keyboard.press("Escape");
};

/** Repeat on, the case most timing-sensitive specs need. */
const enableRepeat = (page: Page): Promise<void> => setMode(page, "playerRepeat", "on");

/**
 * Open a popover and wait until its box has stopped moving.
 *
 * A POPOVER IS MEASURED ONLY AFTER THIS, because a panel opens with a `rotateY` and a
 * transform is included in `getBoundingClientRect` — so a box read while the panel is
 * still turning is a couple of pixels away from where it lands, and the reading changes
 * with nothing but machine speed. That made every geometry assertion here a coin flip
 * that happened to keep landing heads: "opens upward" failed by 1.3px on one run and
 * 2.9px on the next, both of them against unchanged positioning code.
 * `:popover-open` and visibility are both true from the first frame, so neither is the
 * thing to wait for; two identical boxes in a row is.
 */
const openPopover = async (page: Page, root: string) => {
    await page.locator(`${root} .popover-button`).click();

    const panel = page.locator(`${root} .popover-content`);
    await expect(panel).toBeVisible();

    let previous = "";
    await expect
        .poll(async () => {
            const box = JSON.stringify(await panel.boundingBox());
            const settled = box === previous;
            previous = box;

            return settled;
        })
        .toBe(true);

    return panel;
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

    test("cues a restored queue without fetching a byte of it", async ({ page }) => {
        /*
         * `preload="none"` ON THE BAR'S <audio>, ASSERTED WHERE IT IS VISIBLE. A hydrated
         * queue sets `src` on every page load, and under the "metadata" this element used
         * to carry, the engine answered that by range-hopping the file — front for the
         * Xing header, tail for ID3v1 — a NEW http request per range, each one a full PHP
         * request because the stream route is behind auth. Measured against the real
         * collection: FIVE requests and up to 13 MB per reload of an
         * 83-minute track, with nothing playing and nobody waiting for any of it.
         *
         * It cannot be a Vitest assertion: happy-dom has no network behind an <audio src>,
         * so the attribute would be all there was to check, and checking the attribute is
         * checking the template. What is worth pinning is the CONSEQUENCE — that a reload
         * costs nothing until someone presses play — and only a browser can be asked.
         *
         * The fixture's file is one second long, so this counts requests rather than
         * bytes: the number that must not creep back up is the number of round trips.
         */
        await enqueueSongs(page, 1);

        const streamed: string[] = [];
        page.on("request", request => {
            if (request.url().includes("/stream")) streamed.push(request.url());
        });

        await page.reload();
        await expect(page.locator(".player-bar")).toBeVisible();
        // The element is cued — the queue came back and the src is on it...
        expect((await audioState(page)).src).toMatch(/^\/music\/songs\/[0-9a-f-]{36}\/stream$/u);
        // ...and not one byte was asked for.
        expect(streamed).toHaveLength(0);

        // The press is what fetches, which is the whole point: nothing was lost, it was
        // only deferred to the moment a listener actually wanted the audio.
        await playButton(page).click();
        await expect.poll(() => streamed.length).toBeGreaterThan(0);
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
        await expect.poll(async () => (await audioState(page)).currentTime).toBeGreaterThan(0.1);
        expect((await audioState(page)).paused).toBe(false);

        /*
         * ASSERTED ON THE RAIL'S OWN VALUE, NOT ON THE CLOCK BESIDE IT. The readout is `m:ss`,
         * so it says "0:00" for the whole of the fixture's ONE-SECOND file — the only window in
         * which it says anything else is the sliver between 1.0s and the `ended` that wraps
         * repeat back to zero. Polling for it is a coin flip that comes up heads on an idle
         * machine and tails under a full suite, roughly once in fifty runs.
         *
         * The input carries the same number in seconds, unrounded, which is the honest way
         * to ask "does the UI follow the element" — and it is the value a screen reader is
         * given, so it is worth pinning anyway.
         */
        await expect
            .poll(() => page.locator(".player-timeline__input").inputValue())
            .not.toBe("0");
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
        await expect.poll(async () => (await audioState(page)).currentTime).toBeGreaterThan(0.1);
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
        await expect.poll(async () => (await audioState(page)).buffered).toBeGreaterThan(0);

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
        await openQueuePanel(page);
        await page.locator(".play-queue__load").nth(1).click();
        await expect(page.locator(".player-bar__name")).toHaveText(second);

        // Already playing — the row click is a gesture, and the panel's label says "play".
        await expect(page.locator(".player-bar__name")).toHaveText(first, { timeout: 10_000 });
        expect((await audioState(page)).paused).toBe(false);
    });

    test("plays the track when the row BODY is clicked, not just a control", async ({ page }) => {
        /*
         * The row's play target is an empty button stretched across the whole of it — it cannot
         * hang off the cover, which is the drag grip. That is a HIT AREA, so this is the only
         * layer that can check it —
         * happy-dom has no layout, and a Vitest click on the <li> would reach the handler
         * through the DOM whether the overlay existed or not, asserting nothing.
         *
         * The click lands in the row's left padding at mid-height: inside the row, outside
         * the grip, and clear of the corners (a border-radius clips hit-testing).
         */
        const [, second] = await enqueueSongs(page, 2);
        await enableRepeat(page);

        await openQueuePanel(page);
        const row = page.locator(".play-queue__row").nth(1);
        const box = (await row.boundingBox())!;
        await row.click({ position: { x: 3, y: box.height / 2 } });

        await expect(page.locator(".player-bar__name")).toHaveText(second);
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
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
        await openQueuePanel(page);
        await page.locator(".play-queue__name").nth(1).click({ force: true });

        await expect(page.locator(".player-bar__name")).toHaveText(second);
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
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

        await openQueuePanel(page);
        await page.locator(".play-queue__remove").first().click();

        await expect(page.locator(".play-queue__row")).toHaveCount(1);
    });

    test("opens the volume panel upward, and really attenuates the element", async ({ page }) => {
        /*
         * Two things only a browser can answer.
         *
         * The panel opens UPWARD: the bar is fixed to the bottom of the viewport, so the
         * shared popover style — which pins content under its trigger — would put this
         * off-screen. PlayerVolume overrides the anchor rather than relying on
         * `position-try-fallbacks` to rescue it, and the assertion is that the panel really
         * ends above the button and starts inside the viewport.
         *
         * And the level really reaches the element: `element.volume` is the one fact that
         * says the control is wired to the thing making sound rather than to a ref.
         */
        await enqueueSongs(page, 1);

        await openPopover(page, ".player-volume");
        await expect(page.locator(".player-volume__panel")).toBeVisible();

        const boxes = await page.evaluate(() => {
            const content = document.querySelector(".player-volume .popover-content")!.getBoundingClientRect();
            const button = document.querySelector(".player-volume .popover-button")!.getBoundingClientRect();

            return { panelBottom: content.bottom, panelTop: content.top, buttonTop: button.top };
        });

        // Above the trigger, and not hanging off the top of the window.
        expect(boxes.panelBottom).toBeLessThanOrEqual(boxes.buttonTop + 1);
        expect(boxes.panelTop).toBeGreaterThanOrEqual(0);

        // Drive the real slider: keyboard, so this exercises the native range contract
        // rather than a synthetic value assignment.
        await page.locator(".player-volume__input").focus();
        for (let i = 0; i < 10; i += 1) await page.keyboard.press("ArrowDown");

        const level = await page.evaluate(() => (document.querySelector("audio") as HTMLAudioElement).volume);
        expect(level).toBeLessThan(1);
        expect(level).toBeGreaterThan(0);
    });

    test("switches the trigger to volume_off once the level reaches zero", async ({ page }) => {
        /*
         * The requirement as stated: turning the level to zero switches the BAR's icon,
         * without anything having been muted — so the panel's own button still offers a
         * mute. Driven through the real control, and the qualified `xlink:href` read is
         * needed because a real browser will not hand it back under the bare name (the
         * reverse of the Vitest specs).
         */
        await enqueueSongs(page, 1);

        await page.locator(".player-volume .popover-button").click();
        await page.locator(".player-volume__input").focus();
        await page.keyboard.press("Home");

        const glyph = (selector: string) => () =>
            page.locator(selector).evaluate(el => el.getAttribute("xlink:href"));

        await expect.poll(glyph(".player-volume .popover-button use")).toBe("#volume_off");
        await expect.poll(glyph(".player-volume__mute use")).toBe("#mute");
        expect(await page.evaluate(() => (document.querySelector("audio") as HTMLAudioElement).volume)).toBe(0);
    });

    test("fills every enabled control and leaves a disabled one bare", async ({ page }) => {
        /*
         * The rule the pills encode: a filled control reads as pressable, so the ABSENCE of
         * the fill is what says "not now". Before every control had a background, a muted
         * glyph carried that on its own; now a greyed glyph inside a pill identical to its
         * neighbours' would read as enabled.
         *
         * Asserted as RELATIONSHIPS rather than against hex values, so a palette change does
         * not break it: transparent vs not, and play differing from the quiet pair. Only a
         * browser resolves `light-dark()` and the disabled cascade, so this cannot be a unit
         * test.
         *
         * REDUCED MOTION FIRST, for the same reason the panel-width spec needs it. `next` is
         * briefly DISABLED while the queue is being built — one track means nowhere to go —
         * so when the second arrives it transitions from transparent to the pill over 150ms,
         * and reading the computed fill immediately catches the start of that transition
         * rather than the settled value. Measured: `rgba(0, 0, 0, 0)` with two animations
         * running, `rgb(237, 237, 237)` 600ms later. The transition is declared under
         * `prefers-reduced-motion: no-preference`, so emulating `reduce` removes it outright.
         */
        await page.emulateMedia({ reducedMotion: "reduce" });
        await enqueueSongs(page, 2);

        const fills = await page.evaluate(() => {
            const bg = (el: Element) => getComputedStyle(el).backgroundColor;
            const controls = [...document.querySelectorAll(".player-bar__transport .player-bar__control")];
            const prev = controls[0] as HTMLButtonElement;
            const next = controls[2] as HTMLButtonElement;

            return {
                prevDisabled: prev.disabled,
                prev: bg(prev),
                next: bg(next),
                play: bg(document.querySelector(".player-bar__control--play")!),
                volume: bg(document.querySelector(".player-volume .popover-button")!)
            };
        });

        // At the head of a two-track queue: prev is disabled, next is not.
        expect(fills.prevDisabled).toBe(true);
        expect(fills.prev).toBe("rgba(0, 0, 0, 0)");
        expect(fills.next).not.toBe("rgba(0, 0, 0, 0)");

        // The volume trigger is the look the transport was matched TO, so those agree…
        expect(fills.next).toBe(fills.volume);
        // …and play is the one coloured surface, so it must not.
        expect(fills.play).not.toBe(fills.next);
        expect(fills.play).not.toBe("rgba(0, 0, 0, 0)");
    });

    test("keeps the volume panel one width, whatever the readout says", async ({ page }) => {
        /*
         * The panel sizes to its contents and the readout is the widest thing in it, so
         * "100%" made it a digit wider than "99%" and it twitched under the pointer as the
         * level crossed. The readout now reserves its longest width up front.
         *
         * REDUCED MOTION IS WHAT MAKES THIS MEASURABLE, and it is not a detail: the popover
         * animates in with `transform: rotateY(90deg)`, which turns it edge-on, so every box
         * inside it measures ~0 until the transition finishes. An earlier version of this
         * spec compared 0 against 0 and passed happily with the fix removed. The transition
         * lives under `prefers-reduced-motion: no-preference`, so emulating `reduce` skips it
         * outright and the first measurement is already the final geometry.
         *
         * Measured across the step DOWN FROM 100%, because that is where the character
         * count changes — four characters to three. Which value that lands on follows the
         * slider's own `step`: 95% since it went to 5%, 99% before. The same
         * jump exists at 5% → 10% and the reservation covers both.
         */
        await page.emulateMedia({ reducedMotion: "reduce" });
        await enqueueSongs(page, 1);
        await page.locator(".player-volume .popover-button").click();
        await page.locator(".player-volume__input").focus();

        const panelWidth = async () =>
            (await page.locator(".player-volume .popover-content").boundingBox())!.width;

        await page.keyboard.press("End");
        await expect(page.locator(".player-volume__readout")).toHaveText("100%");
        const atFull = await panelWidth();

        await page.keyboard.press("ArrowDown");
        await expect(page.locator(".player-volume__readout")).toHaveText("95%");
        const oneStepDown = await panelWidth();

        expect(oneStepDown).toBe(atFull);
    });

    test("switches the panel's own button to volume_off when it is pressed", async ({ page }) => {
        // The other half: `mute` is the action, `volume_off` the state once taken.
        await enqueueSongs(page, 1);

        await page.locator(".player-volume .popover-button").click();
        await page.locator(".player-volume__mute").click();

        const glyph = () =>
            page.locator(".player-volume__mute use").evaluate(el => el.getAttribute("xlink:href"));

        await expect.poll(glyph).toBe("#volume_off");
        expect(await page.evaluate(() => (document.querySelector("audio") as HTMLAudioElement).muted)).toBe(true);
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

        await expect.poll(async () => (await audioState(page)).currentTime).toBeGreaterThan(0.1);
        expect(violations).toStrictEqual([]);
    });

    test("keeps playing across an Inertia navigation", async ({ page }) => {
        // The reason the player lives in the layout rather than in a page. A page-level
        // <audio> would be torn down and rebuilt on every click, and the music would stop
        // every time somebody opened an album.
        await enqueueSongs(page, 1);
        await enableRepeat(page);
        await playButton(page).click();
        await expect.poll(async () => (await audioState(page)).currentTime).toBeGreaterThan(0.1);

        await page.locator(".player-bar__name").click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);

        expect((await audioState(page)).paused).toBe(false);
    });
});

test.describe("the player's settings popover", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("sits between the volume button and the transport", async ({ page }) => {
        // Placement is the whole requirement here, and only a browser knows where a grid
        // area landed — happy-dom would report every box at zero.
        await enqueueSongs(page, 1);

        const edges = await page.evaluate(() => {
            const box = (selector: string) => document.querySelector(selector)!.getBoundingClientRect();

            return {
                volumeRight: box(".player-bar__volume").right,
                settings: box(".player-bar__settings").left,
                settingsRight: box(".player-bar__settings").right,
                transport: box(".player-bar__transport").left
            };
        });

        expect(edges.settings).toBeGreaterThanOrEqual(edges.volumeRight);
        expect(edges.transport).toBeGreaterThanOrEqual(edges.settingsRight);
    });

    test("opens upward, like the volume panel beside it", async ({ page }) => {
        // Same reason as the volume popover's own spec: the bar is fixed to the bottom of
        // the viewport, so the shared style's "under the trigger" would put this panel
        // off-screen, and the fallback flip alone lands it a couple of pixels inside the
        // button. Two adjacent triggers getting this wrong differently would be worse than
        // either getting it wrong alone.
        await enqueueSongs(page, 1);
        await openPopover(page, ".player-settings");

        await expect(page.locator(".player-settings__panel")).toBeVisible();

        const boxes = await page.evaluate(() => {
            const content = document.querySelector(".player-settings .popover-content")!.getBoundingClientRect();
            const button = document.querySelector(".player-settings .popover-button")!.getBoundingClientRect();

            return { panelBottom: content.bottom, panelTop: content.top, buttonTop: button.top };
        });

        expect(boxes.panelBottom).toBeLessThanOrEqual(boxes.buttonTop + 1);
        expect(boxes.panelTop).toBeGreaterThanOrEqual(0);
    });

    test("fits the settings panel on a phone, at both common widths", async ({ page }) => {
        /*
         * THE BUG THIS PINS, measured on Android Chrome: cap every floating panel at `50dvw`
         * and that is 206px on a Pixel 7 — where this panel needs 250px for a German label
         * beside its bubbles. It clips its own controls against the right edge and grows a
         * horizontal scrollbar inside itself.
         *
         * Only a browser can answer it: the panel is `width: auto` over `white-space:
         * nowrap` rows, so its natural width comes from real text measured in a real font,
         * and where it lands comes from CSS anchor positioning. Both are engine work that
         * happy-dom has no opinion about.
         *
         * 360 is the second width on purpose. It is not just a smaller 412: the gear's
         * right edge sits 222px in, so a 250px panel anchored to it runs off the LEFT of
         * the screen, and no flip helps — that is the case `--popover-flush-inline` exists
         * for, and this is what would notice if the fallback were dropped.
         */
        await enqueueSongs(page, 1);

        for (const width of [412, 360]) {
            await page.setViewportSize({ width, height: 915 });

            const panel = await openPopover(page, ".player-settings");

            const fit = await panel.evaluate(node => {
                const box = node.getBoundingClientRect();

                return {
                    clipped: node.scrollWidth > node.clientWidth,
                    left: box.left,
                    right: box.right,
                    viewport: window.innerWidth
                };
            });

            // Nothing cut off inside the panel, and the whole panel inside the viewport.
            expect(fit.clipped).toBe(false);
            expect(fit.left).toBeGreaterThanOrEqual(0);
            expect(fit.right).toBeLessThanOrEqual(fit.viewport);
            // Every option still reachable, which is what the two above are really about.
            await expect(page.locator(".player-settings .option-bubbles__item")).toHaveCount(7);

            await page.keyboard.press("Escape");
        }
    });

    test("moves the pill onto the option that was clicked", async ({ page }) => {
        /*
         * The pill is one element behind the row, positioned by a `calc()` off two custom
         * properties — so this is the layer that can say it actually travelled. Vitest can
         * only see the properties; it has no engine to resolve them into a left edge.
         */
        await enqueueSongs(page, 1);
        await page.locator(".player-settings .popover-button").click();

        const pill = page.locator(".player-settings__row").first().locator(".option-bubbles__pill");
        const at = async () => (await pill.boundingBox())!.x;

        const resting = await at();
        await page.locator('label[for="playerMode-on"]').click();

        await expect.poll(async () => (await at()) > resting).toBe(true);
    });

    test("says which track failed when the stream does not answer", async ({ page }) => {
        /*
         * A file that vanished between library scans — the reason the toast exists. It has
         * to be a real browser: a MediaError is minted by the media stack from a real HTTP
         * response, and neither the code nor the event can be produced honestly by a fake.
         *
         * Routed rather than deleted, because the E2E fixture writes a real file at every
         * path the seeder claims (seedMediaFiles). The route is installed BEFORE the track
         * is queued: an <audio> starts fetching the moment its src is set, so a route added
         * after the enqueue would arrive too late to break anything.
         */
        await page.route("**/stream", route => route.fulfill({ status: 404, body: "" }));

        const [title] = await enqueueSongs(page, 1);
        await playButton(page).click();

        const toast = page.locator(".toast-container__item--error").first();
        await expect(toast).toBeVisible();
        await expect(toast).toContainText(title);
        // And the transport is honest about it: the glyph is back on play rather than
        // offering to pause silence.
        await expect(playButton(page)).toHaveAttribute("aria-label", "Abspielen");
    });

    test("really shuffles: every track once, then the queue is done", async ({ page }) => {
        /*
         * Shuffle is a play MODE, and this is the promise it makes — a pass plays each
         * track exactly once and then stops, whatever order it picked. Asserted as a SET
         * rather than a sequence, which is what makes a random control testable at all: any
         * order passes, a repeat inside one pass does not.
         *
         * Three one-second tracks, driven by nothing but `ended` — the same event
         * auto-advance rides everywhere else in this file.
         */
        const queued = await enqueueSongs(page, 3);
        await setMode(page, "playerMode", "on");

        const heard = new Set<string>([await page.locator(".player-bar__name").innerText()]);
        await playButton(page).click();

        await expect
            .poll(
                async () => {
                    heard.add(await page.locator(".player-bar__name").innerText());

                    return (await audioState(page)).paused && heard.size === 3;
                },
                { timeout: 20_000, intervals: [100] }
            )
            .toBe(true);

        expect([...heard].sort()).toStrictEqual([...queued].sort());
    });
});
