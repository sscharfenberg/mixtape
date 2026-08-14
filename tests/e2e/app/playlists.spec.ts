import { expect, test } from "@playwright/test";
import type { Page, Response } from "@playwright/test";
import { specStorageState } from "../support/environment";

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
 * Second, and sharper: no test may use a row another test made. Open the row a previous test
 * left and it fails on a stamp from a PREVIOUS RUN, because `STAMP` is evaluated at module load
 * and this file's tests do not share one module instance. A test that builds its own fixture
 * cannot be broken by where it runs — which is also what makes `mode: "serial"` below free of
 * consequences.
 *
 * THE FLAKE THIS FILE PINS, and both halves of its fix. "Edits a playlist's metadata" fails
 * about one full run in five, with the listing showing the PRE-EDIT blurb afterwards. Asserting
 * the SAVE'S PAYLOAD splits the question in two — the form sends the old text, so it is never the
 * server — and a 10ms probe on the form element finds the rest: the `<form>` is REPLACED twelve
 * to twenty milliseconds after the field is filled, its replacement seeded from the props again,
 * and `<Form>` reads the DOM at submit.
 *
 * The trigger was the menu's hover `prefetch`: a click that outruns the hover timer sends its own
 * request, so the prefetch is neither cached nor consumed, and Inertia applies its late response to
 * the page you are NOW on (`Response.handlePrefetch()` → `handle()` when the URL matches), which
 * re-creates the page component. Both ends are fixed — the link no longer prefetches
 * (PlaylistMenu), and the form remembers its fields (PlaylistMetadataPage), so any other swap is
 * harmless. "Keeps what the reader typed" covers the second; the payload assertion below stays as
 * the tripwire for the first.
 *
 * Ruled out by measurement on the way, so none of it needs re-doing: the browser cache (the listing
 * answers `no-cache, private`, and a reload really re-fetches), a `description` missing from
 * `validated()`, a precognitive request reaching the controller, the queue sync (it uses `fetch`,
 * not the router), a locator collision, and a late prefetch on its own — held back two seconds on
 * purpose, the field survived that, which is what pointed at the double-handling rather than the
 * response itself.
 */
/*
 * AN ACCOUNT OF ITS OWN, AND ONE WORKER — the fix for a flake that pointed at playlists and
 * was neither about playlists nor about this spec's assertions.
 *
 * "Will not submit a nameless playlist" found no error message, and "edits a playlist's
 * metadata" found no toast, together about one full run in five. Both of those travel in the
 * SESSION: Inertia flashes validation errors and flash messages, and Laravel does not lock
 * sessions — it reads the whole payload at the start of a request and writes the whole payload
 * at the end. Two concurrent requests on one cookie therefore lose one of the two writes, and
 * this is the only spec on the shared account that writes through the app and then asserts what
 * the flash said. Measured: 10/10 green on one worker, 2-in-14 red on three, 42/42 green on
 * three once each worker had a session of its own.
 *
 * `serial` IS THE SECOND HALF AND IS NOT OPTIONAL. An account of its own only removes the
 * OTHER specs; under `fullyParallel: true` this file's own tests would go on racing each other
 * on that one session. It costs nothing measurable — 36.3s on one worker against 36.5s on
 * three, this file's tests being short and its per-worker setup not being — and it hands the
 * other two workers back to the rest of the run.
 *
 * It also finally makes the ordering real. This said `mode: "default"` and a comment claiming
 * that kept the tests in order, which `fullyParallel: true` had quietly overridden: "default"
 * restores the PROJECT default, and the project default is parallel. Nothing here relied on
 * the ordering — every test builds its own fixture — so the note was harmless, but it was
 * wrong.
 */
test.use({ storageState: specStorageState("playlists") });
test.describe.configure({ mode: "serial" });

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
const HOVERED = `E2E Zeiger ${STAMP}`;
const SORTED = `E2E Sortiert ${STAMP}`;
const EDITED = `E2E Bearbeitet ${STAMP}`;

/**
 * A response to the WRITE, not to a live-validation request that happens to share its verb.
 *
 * BOTH FORMS ON THIS PAGE VALIDATE THROUGH PRECOGNITION, and a precognitive request goes to the
 * same URL with the same method — measured: `PUT /playlists/{id}` with
 * `Precognition: true` and `Precognition-Validate-Only: description`, fired by the `change` event
 * that `fill()` dispatches. So a matcher on url + method alone resolves on the VALIDATION and says
 * the save landed when nothing has been saved at all. That is not hypothetical tidying: it is why
 * "edits a playlist's metadata" could reach its final assertion with the write still in the air, or
 * never sent, and then fail on a stale listing several steps away from the cause.
 *
 * The real write is the one Inertia sends: `X-Inertia`, and no Precognition header.
 */
const isWrite = (response: Response, method: "POST" | "PUT"): boolean =>
    response.request().method() === method && response.request().headers().precognition === undefined;

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
        response => response.url().endsWith("/playlists") && isWrite(response, "POST")
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

/**
 * Wait until the wall clock ticks into the next second.
 *
 * IT IS A REAL SLEEP AND IT HAS TO BE ONE, because the thing being waited for is the clock
 * itself. `playlists.created_at` / `updated_at` are stored at SECOND precision — Laravel's
 * `timestamps()` and Eloquent's default date format both stop there — and the listing decides
 * "has this been changed?" by comparing the two (PlaylistsController). A create and an edit
 * inside one second are therefore indistinguishable from no edit at all, and this journey is
 * fast enough on a warm machine to fit in one: measured on the metadata test, where the row came
 * back `created_at == updated_at == 04:31:02` and the "Geändert" chip was correctly absent.
 * Nothing in the app is wrong there; the test is asserting a difference it left no room for.
 *
 * It waits only the REMAINDER of the current second (plus a small margin), so it costs under a
 * second and never a whole one — and it is called from the one test that needs an edit to be
 * *later* than its create, not from `createPlaylist` itself, where every other test would pay
 * for it.
 */
const intoNextSecond = async (page: Page): Promise<void> => {
    await page.waitForTimeout(1000 - (Date.now() % 1000) + 50);
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

    test("lights an entry under the pointer: the fill shifts and a halo comes up", async ({ page }) => {
        /*
         * Pure CSS, so only a browser can see it — and worth seeing, because both halves are
         * silent when they break. A mistyped token leaves the fill unchanged and a lost
         * `&:hover` leaves the halo off, and either way the entry simply stops responding to
         * the pointer with no error anywhere.
         *
         * BOTH are asserted because neither carries the signal alone: the fill is one rung
         * along the grey ladder (deliberately subtle) and the halo does the shouting — the
         * same split the DataTable's own row-hover settled on.
         */
        await createPlaylist(page, HOVERED);
        const row = page.locator("li.playlist", { hasText: HOVERED });

        const paint = () =>
            row.evaluate(el => {
                const cs = getComputedStyle(el);

                return { background: cs.backgroundColor, shadow: cs.boxShadow };
            });

        /*
         * THE POINTER IS PARKED SOMEWHERE, and after the redirect that somewhere is over an
         * entry: Playwright leaves the mouse where the last click put it, and the submit
         * button's coordinates land on a row once the listing renders. Read straight away,
         * the "resting" state was the halo 22% of the way through its fade — a shadow with a
         * fifth of the alpha and a fifth of the blur, which looks like nothing in particular
         * and matches neither state. So: move off first, then wait for the fade to finish
         * before calling it rest.
         */
        await page.mouse.move(5, 5);
        await expectSlow.poll(async () => (await paint()).shadow).toBe("none");
        const rest = await paint();

        await row.hover();
        await expectSlow.poll(async () => (await paint()).background).not.toBe(rest.background);
        await expectSlow.poll(async () => (await paint()).shadow).not.toBe("none");
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

        await expectSlow(row.getByRole("link", { name: /^Metadaten bearbeiten$/u })).toBeVisible();
        await expectSlow(page).toHaveURL(/\/playlists$/u);
    });

    test("edits a playlist's metadata through the menu, and says it did", async ({ page }) => {
        /*
         * The edit journey end to end, which neither other layer can see whole: the menu
         * carries the row's id, the form arrives already filled in, the save PUTs and lands
         * back on the listing with the new name on it.
         *
         * The prefilled fields are the assertion worth having twice over. UpdatePlaylistTest
         * proves the server SENDS the metadata and PlaylistMetadataPage.test.ts proves the
         * page seeds its refs from it — but only a browser proves the two meet across a real
         * Inertia visit, and a form that opens empty would silently blank whatever the reader
         * had written the moment they pressed Save.
         */
        await createPlaylist(page, EDITED, "Erste Fassung.");
        // The edit has to land in a LATER second than the create, or the listing is right to say
        // nothing has changed — see `intoNextSecond`.
        await intoNextSecond(page);

        const row = page.locator("li.playlist", { hasText: EDITED });
        await row.locator(".popover button").click();
        await row.getByRole("link", { name: /^Metadaten bearbeiten$/u }).click();

        await page.waitForURL(/\/playlists\/[0-9a-f-]+\/edit$/u);
        await expectSlow(page.locator("#name")).toHaveValue(EDITED);
        await expectSlow(page.locator("#description")).toHaveValue("Erste Fassung.");

        await page.locator("#description").fill("Zweite Fassung.");
        const saved = page.waitForResponse(
            response => /\/playlists\/[0-9a-f-]+$/u.test(response.url()) && isWrite(response, "PUT")
        );
        await page.getByRole("button", { name: /^Änderungen speichern$/u }).click();
        const save = await saved;

        /*
         * WHAT THE SAVE CARRIED, and this assertion is why the flake is understood rather than
         * merely gone: the listing alone cannot tell "the form sent the old text" from "the server
         * ignored the new text", and those are bugs in different files. It said the first, which
         * turned a stale row into a client-side hunt (the file header has the rest).
         *
         * It stays as the tripwire. The trigger that was found is fixed at both ends, but anything
         * that re-creates this page mid-edit would land here again — and this is the assertion that
         * would say so in one line instead of leaving a stale row to be explained.
         */
        const sent = JSON.parse(save.request().postData() ?? "{}") as { description?: string };
        expect(sent.description, "the form must send what the reader typed, not what it was given").toBe(
            "Zweite Fassung."
        );

        await page.waitForURL(/\/playlists$/u);
        /*
         * RELOADED rather than trusting what the redirect rendered. The subject here is that
         * the edit was SAVED, and a reload reads the listing from the database instead of from
         * whatever response Inertia happened to have in hand — which is both a stronger claim
         * and immune to the stale render this saw once in a full three-worker run (the PUT had
         * returned, and the listing still showed the pre-edit blurb for 15 seconds).
         */
        await page.reload();
        const edited = page.locator("li.playlist", { hasText: EDITED });
        await expectSlow(edited.locator(".playlist__description")).toHaveText("Zweite Fassung.");
        // An edit is a change, so the listing now has something to say about one.
        await expectSlow(edited.getByText("Geändert", { exact: true })).toBeVisible();
    });

    test("keeps what the reader typed when the page is rebuilt underneath them", async ({ page }) => {
        /*
         * THE HALF OF THE FLAKE THAT WAS THIS APP'S TO FIX. Under load, roughly one save in twenty
         * wrote the value the server had SENT rather than the one just typed. Measured at 10ms
         * resolution: the `<form>` element was replaced 12–20ms after a field was filled, and the
         * replacement carried the props again — Inertia's Vue adapter re-keys the page component on
         * any swap that does not preserve state, so `setup()` re-ran and the refs went back to the
         * prop, while `<Form>` reads the DOM at submit.
         *
         * The trigger was a hover `prefetch` on the menu's edit link, whose response Inertia
         * applies to the page you are now on; that is gone (PlaylistMenu says why). This test
         * covers the SECOND half — `useRemember` on the two fields — because the trigger is not the
         * only thing that can re-create a page, and losing typed text is the part that hurts.
         *
         * Back-then-Forward is the same mechanism a reader can actually reach, and the only one a
         * test can ask for on purpose: the page is destroyed and rebuilt in the same history entry,
         * which is exactly the case `useRemember` restores from. Without it, this comes back
         * holding "Erste Fassung." — verified by removing the remember and watching it fail.
         */
        const name = `E2E Erinnert ${STAMP}`;
        await createPlaylist(page, name, "Erste Fassung.");

        const row = page.locator("li.playlist", { hasText: name });
        await row.locator(".popover button").click();
        await row.getByRole("link", { name: /^Metadaten bearbeiten$/u }).click();
        await page.waitForURL(/\/playlists\/[0-9a-f-]+\/edit$/u);
        await expectSlow(page.locator("#description")).toHaveValue("Erste Fassung.");

        await page.locator("#description").fill("Halb getippt.");

        await page.goBack();
        await page.waitForURL(/\/playlists$/u);
        await page.goForward();
        await page.waitForURL(/\/playlists\/[0-9a-f-]+\/edit$/u);

        await expectSlow(page.locator("#description")).toHaveValue("Halb getippt.");
    });

    test("re-saving a playlist without renaming it is not a clash with itself", async ({ page }) => {
        // The unique rule ignores the row being edited. Without that, pressing Save on an
        // untouched name reports it as taken by the very playlist wearing it — and only a
        // real submit through the real form shows the reader what that looks like.
        const name = `E2E Unverändert ${STAMP}`;
        await createPlaylist(page, name);

        const row = page.locator("li.playlist", { hasText: name });
        await row.locator(".popover button").click();
        await row.getByRole("link", { name: /^Metadaten bearbeiten$/u }).click();
        await page.waitForURL(/\/playlists\/[0-9a-f-]+\/edit$/u);

        const saved = page.waitForResponse(
            response => /\/playlists\/[0-9a-f-]+$/u.test(response.url()) && response.request().method() === "PUT"
        );
        await page.getByRole("button", { name: /^Änderungen speichern$/u }).click();
        await saved;

        await page.waitForURL(/\/playlists$/u);
        await expectSlow(page.getByText("Du hast bereits eine Wiedergabeliste mit diesem Namen.")).toHaveCount(0);
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

    test("reorders the listing by dragging a grip, and keeps the new order", async ({ page }) => {
        /*
         * THE ONLY LAYER THAT CAN SEE A DRAG. Vitest mocks SortableJS out deliberately — a drag
         * is a stream of pointer events over elements with real geometry, and happy-dom has
         * neither, so a "drag" there would assert the mock. The keyboard path (Alt+↑/↓) goes
         * through the same `move()` and IS covered there, cheaply.
         *
         * The reload is the point of the test as much as the drag: the move is optimistic, so
         * without it this would pass on a `splice()` that never reached the server.
         *
         * Sortable runs with `forceFallback`, which is what makes this drivable: its own
         * pointer path rather than native HTML5 dragging, so plain mouse moves work.
         */
        const names = [`${SORTED} A`, `${SORTED} B`, `${SORTED} C`];
        for (const name of names) await createPlaylist(page, name);

        const order = async () =>
            (await page.locator(".playlist__title").allTextContents())
                .map(text => text.trim())
                .filter(title => title.startsWith(SORTED));

        await expectSlow.poll(order).toStrictEqual(names);

        // Drag the LAST of the three onto the first, by its grip.
        const rows = page.locator("li.playlist", { hasText: SORTED });

        /*
         * SCROLLED INTO VIEW FIRST, because `boundingBox()` does not do it. Every test above
         * leaves a playlist behind, so by the time this runs these three sit below the fold —
         * and the coordinates then describe a point off screen, where the mouse moves land on
         * nothing at all. The drag "worked" against a three-entry listing and did nothing here,
         * which is the whole difference.
         */
        await rows.nth(0).scrollIntoViewIfNeeded();

        const grip = (await rows.nth(2).locator(".playlist__handle").boundingBox())!;
        const target = (await rows.nth(0).boundingBox())!;

        await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
        await page.mouse.down();
        // Stepped, and onto the destination's upper region: Sortable decides where to insert
        // from where the pointer sits inside the row it is over, and only sees positions it
        // actually visits.
        await page.mouse.move(target.x + target.width / 2, target.y + target.height * 0.2, { steps: 20 });
        await page.mouse.up();

        await expectSlow.poll(order).toStrictEqual([names[2], names[0], names[1]]);

        // And it is the SERVER's order now, not a local splice.
        await page.reload();
        await expectSlow.poll(order).toStrictEqual([names[2], names[0], names[1]]);
    });

    test("lets the reader back out to the listing without creating anything", async ({ page }) => {
        await page.goto("/playlists/create");
        await page.locator("#name").fill(DISCARDED);

        await page.getByRole("link", { name: /^Abbrechen$/u }).click();
        await page.waitForURL(/\/playlists$/u);

        await expectSlow(page.locator("li.playlist", { hasText: DISCARDED })).toHaveCount(0);
    });
});
