import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { openQueuePanel, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * The player's keyboard shortcuts, in a real engine.
 *
 * The mapping and every guard are covered by usePlayerShortcuts' own Vitest spec, against
 * synthetic events. Four things here are structurally unavailable to it, and all four are
 * the difference between "the handler ran" and "the music did what was asked":
 *
 *   - A REAL KEY PRESS, dispatched by the browser rather than constructed. `page.keyboard`
 *     goes through the same path a person does, including which element the event is
 *     targeted at — which is the input to every guard.
 *   - A REAL <audio> ELEMENT ACTUALLY PLAYING. happy-dom has no decoder, so `playbackRate`
 *     there is a property nobody reads; here it changes the rate of a file being decoded,
 *     and the cursor is what proves it.
 *   - PAGE SCROLLING. "Space must not scroll while a track is loaded, and must scroll again
 *     once the queue is empty" is a statement about layout and default actions. There is no
 *     scroll position without a viewport.
 *   - A REAL PASSWORD FIELD ON A REAL FORM, typed into. The Vitest guard test builds an
 *     <input> by hand; this puts a space into the actual dashboard field, which is the
 *     thing the owner asked about.
 *
 * The fixture's tracks are one second of audio claiming two to eight minutes
 * (docs/testing.md), so any assertion here is about STATE and DIRECTION, never about a
 * position derived from the duration.
 */

/*
 * ITS OWN ACCOUNT, AND A CLEAN QUEUE PER TEST. The play queue is server state since the
 * `player_states` sync landed, so a fresh browser context is no longer a fresh player: a
 * queue follows the USER. Sharing one account across files let a spec in one worker restore
 * a queue another worker had just left, and sharing it across tests in this file let each
 * test inherit the last one's. The account is this file's alone (E2ESeeder seeds it,
 * auth.setup mints its session) and the reset below is what tests here owe each other.
 */
test.use({ storageState: specStorageState("shortcuts") });

/*
 * SEQUENTIAL, IN ONE WORKER, which the account above is worthless without. `fullyParallel`
 * parallelises at the TEST level, not the file level — so without this the tests in this
 * file run CONCURRENTLY against the one account they share, and each sees the others'
 * queues. That failed as a count one too high, on a different test every run.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("shortcuts");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`,
// so that request can outlive the test and land after the NEXT one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** Put the first song in the queue, so the bar exists and the shortcuts are bound. */
const queueASong = async (page: Page): Promise<void> => {
    await page.goto("/music/songs");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    await page.locator(".hero-section__menu .popover-button").click();
    await page.locator(".hero-section__menu .popover-list-item").nth(1).click();
    await expect(page.locator(".play-queue__row")).toHaveCount(1);
    await expect(page.locator(".player-bar")).toBeVisible();
};

/** What the one <audio> element currently reports. */
const audioState = (page: Page) =>
    page.evaluate(() => {
        const audio = document.querySelector("audio")!;

        return { paused: audio.paused, rate: audio.playbackRate, time: audio.currentTime, volume: audio.volume };
    });

test.describe("the player's keyboard shortcuts", () => {
    test.beforeEach(async ({ page }) => {
        await queueASong(page);
        // Nothing may have focus, or the guards would (correctly) stand aside — this is the
        // ordinary state after a click on the page background.
        await page.locator("body").click({ position: { x: 5, y: 5 } });
    });

    test("plays and pauses on Space, without the page scrolling under it", async ({ page }) => {
        const before = await page.evaluate(() => window.scrollY);

        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
        expect(await page.evaluate(() => window.scrollY)).toBe(before);

        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(true);
    });

    test("really doubles the speed while Space is held, and puts it back on release", async ({ page }) => {
        // The property Vitest cannot check: a rate applied to an element that is decoding.
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.keyboard.down("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(2);
        await expect(page.locator(".player-bar__rate")).toBeVisible();

        await page.keyboard.up("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(1);
        await expect(page.locator(".player-bar__rate")).toBeHidden();
    });

    test("keeps playing after a hold, rather than pausing on the release", async ({ page }) => {
        // The reason the toggle waits for key-up in the first place: a skim that ended by
        // pausing would be worse than no skim at all.
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.keyboard.down("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(2);
        await page.keyboard.up("Space");

        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
    });

    test("keeps the pitch, so a skim is quick rather than unlistenable", async ({ page }) => {
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.keyboard.down("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(2);

        expect(await page.evaluate(() => document.querySelector("audio")!.preservesPitch)).toBe(true);
        await page.keyboard.up("Space");
    });

    test("seeks with the arrows and steps the queue with Shift", async ({ page }) => {
        await page.keyboard.press("ArrowRight");
        await expect.poll(async () => (await audioState(page)).time).toBeGreaterThan(0);

        await page.keyboard.press("ArrowLeft");
        await expect.poll(async () => (await audioState(page)).time).toBe(0);

        // One track queued, so a forward step has nowhere to go and must not crash or empty
        // the queue — the ends of the queue are as much a case as the middle.
        await page.keyboard.press("Shift+ArrowRight");
        await expect(page.locator(".play-queue__row")).toHaveCount(1);
    });

    test("attenuates the element itself with the up and down arrows", async ({ page }) => {
        await page.keyboard.press("ArrowDown");
        await page.keyboard.press("ArrowDown");

        await expect.poll(async () => (await audioState(page)).volume).toBeLessThan(1);

        await page.keyboard.press("KeyM");
        await expect.poll(() => page.evaluate(() => document.querySelector("audio")!.muted)).toBe(true);
    });

    test("shows the new level in the middle of the screen, and lets a click through it", async ({ page }) => {
        /*
         * The arrows are the gesture with no control on screen — the slider they move is
         * inside a closed popover — so this box is the whole of their feedback. Three of
         * its four properties are engine facts and cannot be checked anywhere else:
         *
         *   - WHERE IT LANDS. It is `position: fixed` in a component written inside a bar
         *     that carries `backdrop-filter`, which makes that bar the containing block for
         *     fixed descendants — so "the middle of the viewport" is a claim about which
         *     element the browser resolved the position against. It teleports out for
         *     exactly that reason, and this is what would notice if it stopped.
         *   - THAT A CLICK GOES THROUGH IT. Asked of `elementFromPoint`, which answers with
         *     what a click would actually hit rather than with a computed style.
         *   - THAT IT GOES AWAY BY ITSELF, on a real timer.
         */
        await page.keyboard.press("ArrowDown");

        const hud = page.locator(".player-volume-hud");
        await expect(hud).toBeVisible();
        // One 5% step down from the browser's own unity gain.
        await expect(hud).toHaveText("95%");

        const geometry = await page.evaluate(() => {
            const box = document.querySelector(".player-volume-hud")!.getBoundingClientRect();
            /*
             * THE MIDDLE OF THE LAYOUT VIEWPORT, WHICH IS NOT `window.innerWidth / 2`. A
             * fixed box resolves `left: 50%` against the viewport MINUS the scrollbar, and
             * this app reserves one on the root permanently (`overflow-y: scroll`, so the
             * page cannot twitch as content changes height). Measured here: the window is
             * 1280 and the box centres on 632.5, which is half of 1265 — dead centre of
             * everything the reader can actually see, and 7.5px off the window's middle.
             * Subtracting the scrollbar rather than hard-coding 15px keeps this true where
             * scrollbars are overlays and take no width at all.
             */
            const scrollbar = window.innerWidth - document.body.clientWidth;
            const middleX = (window.innerWidth - scrollbar) / 2;
            const middleY = window.innerHeight / 2;

            return {
                offsetX: Math.abs(box.left + box.width / 2 - middleX),
                offsetY: Math.abs(box.top + box.height / 2 - middleY),
                catchesTheClick: document.elementFromPoint(middleX, middleY)?.classList.contains("player-volume-hud")
            };
        });

        expect(geometry.offsetX).toBeLessThan(1);
        expect(geometry.offsetY).toBeLessThan(1);
        expect(geometry.catchesTheClick).toBe(false);

        // A couple of seconds, then gone — without another key being touched.
        await expect(hud).toBeHidden({ timeout: 5_000 });
    });

    test("takes nothing from a password field, so a space in a passphrase is just a space", async ({ page }) => {
        /*
         * The case the owner raised. A real form, a real password input, a real space.
         * Asserted on the FIELD as well as the player: the character has to arrive, not
         * merely fail to reach the player.
         *
         * TWO THINGS HAD TO BE GOT RIGHT before this could observe anything, and both made
         * it fail against a guard that was working perfectly:
         *
         *   - reached by CLICKING through the app, not `page.goto`. A hard navigation tears
         *     the <audio> element down, and the queue rehydrates deliberately silent (see
         *     usePlayerQueue), so the player is paused on arrival for reasons that have
         *     nothing to do with the keyboard.
         *   - REPEAT ON FIRST. The fixture's audio is one second long while the row claims
         *     minutes (docs/testing.md), so a single queued track is over before the
         *     navigation finishes and the player is legitimately stopped. Repeat makes it
         *     loop, which is what keeps "still playing" a meaningful assertion — and it is
         *     turned on with the R shortcut, so the setup exercises the keymap too.
         */
        await page.keyboard.press("KeyR");
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.locator(".user-menu .popover-button").click();
        await page.locator(".user-menu .popover-list-item", { hasText: /Einstellungen|Dashboard/u }).first().click();
        await page.waitForURL(/\/dashboard/u);
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.locator("#current_password").focus();
        await page.keyboard.type("mein pass wort");

        await expect(page.locator("#current_password")).toHaveValue("mein pass wort");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
        await expect.poll(async () => (await audioState(page)).rate).toBe(1);
    });

    test("leaves Space to a focused button, which is what Space is for there", async ({ page }) => {
        // Submitting a form with the keyboard must not also pause the music.
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.locator(".player-bar__control--play").focus();
        await page.keyboard.press("Space");

        // The button was pressed — so the player DID pause, but via the click, once.
        await expect.poll(async () => (await audioState(page)).paused).toBe(true);

        // And the shortcut did not fire a second time on top of it: one press, one change.
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);
    });

    test("plays at the speed chosen in the settings, and remembers it", async ({ page }) => {
        /*
         * The setting reaching a decoding element, and surviving a reload — neither of which
         * a fake element can answer. 3× is the top option and the one worth proving: measured
         * in this engine against a synthesised tone, 1×–6× are all honoured exactly with the
         * level unchanged and the pitch held, so nothing here is near a platform limit.
         */
        await page.locator(".player-settings .popover-button").click();
        // The LABEL, not the input: OptionBubbles clips its radios to a pixel so they stay
        // focusable without being visible, and Playwright will not `check()` what it cannot
        // see. The same target preferences.spec.ts uses for the colour-scheme picker.
        await page.locator('label[for="playerSpeed-3"]').click();

        await expect.poll(async () => (await audioState(page)).rate).toBe(3);
        // The VISIBLE half only: the badge also carries an `.sr-only` phrase, which
        // `toHaveText` on the wrapper would concatenate into "3×3-fache Geschwindigkeit".
        await expect(page.locator('.player-bar__rate [aria-hidden="true"]')).toHaveText("3×");

        // A hard reload: the module forgets, localStorage does not, and the speed has to be
        // on the element BEFORE anything can be heard rather than after the first play.
        await page.reload();
        await expect(page.locator(".player-bar")).toBeVisible();

        await expect.poll(async () => (await audioState(page)).rate).toBe(3);
    });

    test("skims at double whatever is set, not at an absolute 2x", async ({ page }) => {
        // At a 3× setting an absolute skim would SLOW the listener down, and the release
        // would strand them at normal instead of back at what they chose.
        await page.locator(".player-settings .popover-button").click();
        await page.locator('label[for="playerSpeed-3"]').click();
        await page.keyboard.press("Escape");

        await page.locator("body").click({ position: { x: 5, y: 5 } });
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.keyboard.down("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(6);
        await expect(page.locator('.player-bar__rate [aria-hidden="true"]')).toHaveText("6×");

        await page.keyboard.up("Space");
        await expect.poll(async () => (await audioState(page)).rate).toBe(3);
    });

    test("shows the live rate in the settings row while the skim is on", async ({ page }) => {
        /*
         * THE PATH MATTERS, and it is not the obvious one. Opening the gear puts focus on the
         * first radio, and a focused radio owns Space — so the common "open the popover, hold
         * Space" gesture correctly does NOT skim, and this readout would never appear. The
         * reachable path is a click on the panel's own non-interactive text, which moves
         * focus to the dialog and leaves Space to the player. Measured, not assumed: with
         * focus on the gear or a bubble the rate stayed put, and only the label click let it
         * move (2× → 4×).
         *
         * The readout is also why the popover must not resize: it is reserved rather than
         * conditional, so the panel's width is asserted across the change.
         */
        await page.locator(".player-settings .popover-button").click();
        await page.locator('label[for="playerSpeed-2"]').click();

        const panel = page.locator(".player-settings__panel");
        const live = page.locator(".player-settings__live");
        const widthOf = () => panel.evaluate(node => Math.round(node.getBoundingClientRect().width));

        /*
         * WAIT FOR THE PANEL TO STOP MOVING before measuring it. The popover scales in, and
         * `getBoundingClientRect` reports the TRANSFORMED box — so a width taken too early is
         * a fraction of the real one (measured 217 against a settled 256) and the comparison
         * below then "fails" for a reason that has nothing to do with the readout. The same
         * trap preferences.spec.ts documents for the colour-scheme pill; it only showed up in
         * the full-file run, where the machine is busy enough to change the timing.
         */
        const settled = async (): Promise<number> => {
            let previous = -1;
            await expect
                .poll(async () => {
                    const current = await widthOf();
                    const stable = current === previous;
                    previous = current;

                    return stable;
                })
                .toBe(true);

            return widthOf();
        };

        await expect(live).toBeHidden();
        const restingWidth = await settled();

        // Focus the dialog itself, which is what leaves Space to the player.
        await page.locator(".player-settings__label").first().click();
        await page.keyboard.press("Space");
        await expect.poll(async () => (await audioState(page)).paused).toBe(false);

        await page.keyboard.down("Space");

        await expect(live).toBeVisible();
        await expect(live).toHaveText("▸ 4×");
        // The pill has NOT moved: it marks the setting, and 4× is not one of the options.
        await expect(page.locator('.player-settings input[value="2"]')).toBeChecked();
        expect(await widthOf()).toBe(restingWidth);

        await page.keyboard.up("Space");
        await expect(live).toBeHidden();
        expect(await widthOf()).toBe(restingWidth);
    });

    test("hands Space back to the page once the queue is empty", async ({ page }) => {
        /*
         * The scoping rule, and the reason the listener lives on PlayerBar: an app that
         * quietly stopped Space from scrolling on every page forever would be a worse bug
         * than any it fixed. Checked on a page long enough to scroll.
         */
        await openQueuePanel(page);
        await page.locator(".play-queue .popover-button").click();
        // By the `--caution` variant, not by position: the repeat toggle sits above it.
        await page.locator(".play-queue .popover-list-item--caution").click();
        await expect(page.locator(".player-bar")).toHaveCount(0);

        await page.goto("/music/songs");
        await expect(page.locator("tbody tr").first()).toBeVisible();
        await page.locator("body").click({ position: { x: 5, y: 5 } });

        const before = await page.evaluate(() => window.scrollY);
        await page.keyboard.press("Space");

        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(before);
    });
});
