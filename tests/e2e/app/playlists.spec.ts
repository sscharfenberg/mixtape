import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * Making a playlist, in a real engine.
 *
 * The server side is pinned by CreatePlaylistTest — what validates, what is stored, where
 * the redirect goes — and the listing's rendering by PlaylistsPage.test.ts (including the
 * empty state, which needs no browser). Three things here are unavailable to either, and
 * all three are what a reader actually does:
 *
 *   - THE WHOLE JOURNEY AS ONE MOVE: the listing offers the form, the form posts, and the
 *     app lands back on the listing with the new playlist on it. Each half is proven
 *     separately; that they meet is not.
 *   - VALIDATE-ON-BLUR. The name field is checked through Precognition on `change`, which
 *     needs a real focus/blur cycle and a real fetch. This is the field where it earns its
 *     place — names are unique per owner, so "you already have one called that" arrives
 *     while the reader is still in the form rather than after they submit it.
 *   - THE NATIVE MAXLENGTH on both fields. Only a browser enforces it, and it is the
 *     reason a reader never meets the server's length rules at all.
 *
 * EVERY TEST CREATES WHAT IT NEEDS, and that rule was learned twice over here.
 *
 * First: nothing may assume an empty or freshly seeded database. `reuseExistingServer` keeps
 * a server and its data alive between runs, so every name below carries a per-invocation
 * stamp — a clash surfaces as the create form simply refusing to redirect, which reads as a
 * broken redirect rather than as "that playlist already exists".
 *
 * Second, and sharper: no test may use a row another test made. One did — the menu test
 * opened the row the create test had left — and it failed on a stamp from a PREVIOUS run,
 * because `STAMP` is evaluated at module load and this file's tests turned out not to share
 * one module instance. `mode: "default"` is kept for readable ordering, but nothing here
 * relies on it: a test that builds its own fixture cannot be broken by where it runs.
 */
test.describe.configure({ mode: "default" });

/**
 * Assertions in this file, with a window wide enough for a contended write.
 *
 * The suite default is 5000ms, which is exactly the busy timeout the E2E sqlite gives a
 * blocked writer (tests/e2e/support/environment.ts). This is the only spec that writes
 * through the app on nearly every test, and the run puts three workers on that one file —
 * so an assertion made after a write could time out while the write was still legitimately
 * waiting for its lock, and it failed on a different test each time (roughly one full run in
 * six). The number is a property of the environment, not of any one assertion, which is why
 * it is set once here rather than sprinkled over the individual calls.
 */
const expectSlow = expect.configure({ timeout: 15_000 });

/**
 * Suffix making every name in this run unique.
 *
 * The seeded database is normally reset per invocation, so this looks redundant — and is
 * not: `reuseExistingServer` keeps a server (and its data) alive between runs, and a name
 * clash surfaces as the create form simply refusing to redirect, which reads as a broken
 * redirect rather than as "that playlist already exists".
 */
const STAMP = Date.now().toString(36);
const NIGHT_DRIVE = `E2E Nachtfahrt ${STAMP}`;
const DUPLICATE = `E2E Doppelt ${STAMP}`;
const DISCARDED = `E2E Verworfen ${STAMP}`;
const STAGGERED = `E2E Versetzt ${STAMP}`;
const MENU_ROW = `E2E Menü ${STAMP}`;

/**
 * Press "create" and wait for the POST itself to come back.
 *
 * Awaiting the RESPONSE rather than clicking and asserting on what renders: a write here can
 * spend seconds waiting for its lock (see `expectSlow` above), and this bounds that wait by
 * the test timeout instead of by an assertion's. The two together are what made this spec
 * stop failing on a different test each run.
 */
const submitPlaylistForm = async (page: Page): Promise<void> => {
    const posted = page.waitForResponse(
        response => response.url().endsWith("/playlists") && response.request().method() === "POST"
    );

    await page.getByRole("button", { name: /^Wiedergabeliste anlegen$/u }).click();
    await posted;
};

/** Fill the form and create a playlist, ending up back on the listing. */
const createPlaylist = async (page: Page, name: string, description?: string): Promise<void> => {
    await page.goto("/playlists/create");
    await page.locator("#name").fill(name);
    if (description) await page.locator("#description").fill(description);
    await submitPlaylistForm(page);
    await page.waitForURL(/\/playlists$/u);
};

test.describe("the playlists area", () => {
    test("offers the create form from the listing", async ({ page }) => {
        await page.goto("/playlists");

        await page.getByRole("link", { name: /Neue Wiedergabeliste anlegen/u }).click();

        await page.waitForURL(/\/playlists\/create$/u);
        await expectSlow(page.locator("#name")).toBeVisible();
    });

    test("creates a playlist, lands back on the listing and says it did", async ({ page }) => {
        await page.goto("/playlists/create");
        await page.locator("#name").fill(NIGHT_DRIVE);
        await page.locator("#description").fill("Für die Autobahn um zwei.");

        /*
         * NO TOAST ASSERTION HERE, deliberately. A toast auto-dismisses after useToast's
         * DEFAULT_DURATION (5s), so checking for one after awaiting a POST and a redirect is a
         * race against its own lifetime — and on three workers sharing one sqlite file (a
         * blocked write waits up to 5s for its lock) it is a race lost often enough to fail
         * roughly one full run in six. Arming the poll early and widening its window both
         * narrowed it without closing it.
         *
         * It is not worth a flaky test, because nothing here is the only proof: the flash is
         * asserted server-side in CreatePlaylistTest (`assertSessionHas('type', 'success')`)
         * and the flash → toast bridge in ToastContainer.test.ts. What this test uniquely
         * proves is the JOURNEY — that the form posts, redirects, and the new playlist is on
         * the listing — and all of that is below.
         */
        await submitPlaylistForm(page);
        await page.waitForURL(/\/playlists$/u);

        // And the playlist is on the page, with the zero tracks a new one has.
        const row = page.locator("li.playlist", { hasText: NIGHT_DRIVE });
        await expectSlow(row).toBeVisible();
        await expectSlow(row.locator(".playlist__title")).toHaveText(NIGHT_DRIVE);
        await expectSlow(row.locator(".playlist__description")).toHaveText("Für die Autobahn um zwei.");
        await expectSlow(row.getByText("Titel", { exact: true })).toBeVisible();

        // A brand-new playlist has neither a playtime nor a change to report.
        await expectSlow(row.getByText("Dauer", { exact: true })).toHaveCount(0);
        await expectSlow(row.getByText("Geändert", { exact: true })).toHaveCount(0);
    });

    test("does not let the entry's own styles leak onto the page's icons", async ({ page }) => {
        /*
         * A REGRESSION GUARD FOR A CLASS COLLISION, and it has to live in a browser: Vitest
         * loads no stylesheets, so nothing below is observable there.
         *
         * <Icon> puts the sprite symbol's NAME into its class list — `<svg class="icon large
         * playlist">` — and this page's headline and create button both ask for the `playlist`
         * glyph. The entry's panel rule was written as a bare `.playlist`, so it matched those
         * two <svg>s and gave them the panel's grid display, white fill, radius and padding:
         * both glyphs rendered as white rounded blobs. Nothing failed; it was caught by
         * looking at a screenshot. `li.playlist` fixes it, and this is what keeps it fixed.
         */
        await page.goto("/playlists");

        for (const selector of ["h2 svg.icon.playlist", ".playlists__actions svg.icon.playlist"]) {
            const icon = page.locator(selector).first();
            await expectSlow(icon).toBeVisible();

            const styles = await icon.evaluate(el => {
                const cs = getComputedStyle(el);

                return { display: cs.display, background: cs.backgroundColor, padding: cs.padding };
            });

            // A glyph, not a panel: the entry's rule set all three of these.
            expectSlow(styles.display).not.toBe("grid");
            expectSlow(styles.background).toBe("rgba(0, 0, 0, 0)");
            expectSlow(styles.padding).toBe("0px");
        }
    });

    test("staggers the entries' rings, and stops them dead under reduced motion", async ({ page }) => {
        /*
         * Both halves are browser-only. Vitest can see that the template publishes
         * `--playlist-index` (its own spec does), but not that the value becomes a different
         * animation delay per entry — and not that the motion guard works at all, since
         * happy-dom loads no stylesheets and resolves no media queries.
         *
         * The delays are NEGATIVE on purpose: a positive one would leave each ring frozen at
         * its start angle for its own share of a minute before joining in. Negative starts
         * every ring immediately, already that far into the same 20s loop, which is what
         * makes a column drift rather than pulse in unison.
         */
        // Two of its own, so the assertion has neighbours to compare whatever else ran first.
        await createPlaylist(page, `${STAGGERED} A`);
        await createPlaylist(page, `${STAGGERED} B`);

        await expectSlow(page.locator("li.playlist").nth(1)).toBeVisible();

        const ringStyles = () =>
            page.evaluate(() =>
                [...document.querySelectorAll("li.playlist")].map(el => {
                    const cs = getComputedStyle(el, "::before");

                    return { name: cs.animationName, delay: cs.animationDelay };
                })
            );

        const moving = await ringStyles();
        expectSlow(moving.length).toBeGreaterThan(1);
        // Prefix, not the literal name: Vue's scoped-style transform HASHES @keyframes
        // identifiers (`playlist-border-rotate-3e76980c`) and rewrites the animation-name
        // that references them. Asserting the exact string pins a build hash, not behaviour.
        for (const ring of moving) expectSlow(ring.name).toMatch(/^playlist-border-rotate/u);
        // Every entry at its own point in the turn — no two neighbours share an angle.
        expectSlow(new Set(moving.map(ring => ring.delay)).size).toBe(moving.length);

        // And with the preference set, no motion at all: the ring holds the angle its
        // @property registration starts it at. The app-wide rule, per CLAUDE.md → Motion.
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.reload();
        await expectSlow(page.locator("li.playlist").first()).toBeVisible();

        for (const ring of await ringStyles()) expectSlow(ring.name).toBe("none");
    });

    test("opens the row's menu, which the row's own link must not swallow", async ({ page }) => {
        // A <button> inside an <a> renders fine and misbehaves: the press would follow the
        // link as well as open the panel. Only a real engine shows that, and the assertion
        // that catches it is that the menu opens and the page stays put.
        await createPlaylist(page, MENU_ROW);
        const row = page.locator("li.playlist", { hasText: MENU_ROW });

        await row.locator(".popover button").click();

        await expectSlow(row.getByRole("link", { name: /^Bearbeiten$/u })).toBeVisible();
        await expectSlow(page).toHaveURL(/\/playlists$/u);
    });

    test("says a name is taken while the reader is still in the field", async ({ page }) => {
        // Arrange the clash through the form itself, so nothing here depends on a fixture.
        await createPlaylist(page, DUPLICATE);

        await page.goto("/playlists/create");
        await page.locator("#name").fill(DUPLICATE);

        // Blur, which is what fires the precognitive check — no submit anywhere here. Its
        // response is awaited for the same reason the submit helper awaits its own: the
        // assertion below must outlast a round trip on a loaded server, not race it.
        const checked = page.waitForResponse(
            response =>
                response.url().endsWith("/playlists") &&
                response.request().method() === "POST" &&
                response.request().headers().precognition === "true"
        );
        await page.locator("#description").focus();
        await checked;

        await expectSlow(page.getByText("Du hast bereits eine Wiedergabeliste mit diesem Namen.")).toBeVisible();
        // Still on the form, with nothing posted for real.
        await expectSlow(page).toHaveURL(/\/playlists\/create$/u);
    });

    test("will not submit a nameless playlist", async ({ page }) => {
        await page.goto("/playlists/create");
        await submitPlaylistForm(page);

        await expectSlow(page.getByText("Bitte gib der Wiedergabeliste einen Namen.")).toBeVisible();
        await expectSlow(page).toHaveURL(/\/playlists\/create$/u);
    });

    test("caps both fields in the browser, so the server's length rules are never met", async ({ page }) => {
        // maxlength is a UA behaviour; happy-dom does not enforce it.
        await page.goto("/playlists/create");

        await page.locator("#name").fill("x".repeat(300));
        await page.locator("#description").fill("y".repeat(1200));

        await expectSlow(page.locator("#name")).toHaveValue("x".repeat(255));
        await expectSlow(page.locator("#description")).toHaveValue("y".repeat(1000));
    });

    test("lets the reader back out to the listing without creating anything", async ({ page }) => {
        await page.goto("/playlists/create");
        await page.locator("#name").fill(DISCARDED);

        await page.getByRole("link", { name: /^Abbrechen$/u }).click();
        await page.waitForURL(/\/playlists$/u);

        await expectSlow(page.locator("li.playlist", { hasText: DISCARDED })).toHaveCount(0);
    });
});
