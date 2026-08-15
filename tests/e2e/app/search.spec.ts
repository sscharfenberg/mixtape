import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { enqueueFromHero, pageHeading, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * The cross-kind search, in a real engine (docs/search.md).
 *
 * The engine is the server's and is pinned by tests/Feature/Search; the debounce, the abort and
 * the keyboard walk are the composable's and are pinned in Vitest against synthetic events. Four
 * things are structurally unavailable to both layers:
 *
 *   - TYPING MUST NOT DRIVE THE PLAYER. This is the one the design doc singles out, because the
 *     failure would be silent and bizarre — a reader typing a song title while their music seeks
 *     around under them. `usePlayerShortcuts` claims Space and `k j l n p m s r q` on the
 *     document; the only thing that keeps them off this field is a real event with a real target,
 *     which is exactly what a constructed KeyboardEvent cannot provide.
 *   - THE TWO KEYS THAT OPEN IT. `/` and ⌘K are bound on the window, and whether the browser
 *     delivers them to us or to its own find-in-page is not something a fake DOM can answer.
 *   - THE OVERLAY BEING A POPOVER AT ALL. happy-dom has no top layer, so `showPopover` there is a
 *     no-op stub: whether the panel is on screen, whether Escape and an outside click dismiss it,
 *     and whether the field takes focus once it is promoted are all browser facts.
 *   - THE HAND-OFF LANDING SOMEWHERE REAL. "Alle N in Songs anzeigen" is only worth anything if
 *     the listing at the other end opens with its own search filled in.
 *
 * FIXTURE FACTS THESE ASSERTIONS REST ON (database/seeders/E2ESeeder):
 *   - "Paranoid Android" is a title appearing exactly once, so "paranoid" has one unambiguous
 *     answer in one group.
 *   - "queen" is BOTH an album ("The Queen Is Dead") and a song of the same name, which is what
 *     makes it a cross-kind case rather than a list.
 *   - "the" matches far more than five songs, so that group offers a hand-off; it also matches the
 *     artist "The Smiths" and two albums, so the group ORDER is observable.
 *   - "Sigur Rós" is the accent case: typing "ros" must find it.
 */

/*
 * ITS OWN ACCOUNT, AND A CLEAN QUEUE PER TEST. The player test below leaves a queue behind, and
 * the queue is server state that follows the USER — so with a shared account a spec in another
 * worker would restore a queue this one had just left, and the failure would surface two files
 * away from its cause. See SPEC_USERS in the E2E support for the whole argument.
 */
test.use({ storageState: specStorageState("search") });

/*
 * SEQUENTIAL, IN ONE WORKER, which the account above is worthless without: `fullyParallel`
 * parallelises at the TEST level, so without this the tests here run concurrently against the one
 * account they share.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("search");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`, so that
// request can outlive the test and land after the next one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

/** The overlay's field, which is also the only search field on a page that is not /music. */
const field = (page: Page) => page.locator(".search-panel .search-field__input");

/** Open the overlay with the header's glyph and wait for the field to be ready to type into. */
const openOverlay = async (page: Page): Promise<void> => {
    await page.locator(".search-toggle").click();
    await expect(page.locator(".search-panel")).toBeVisible();
    await expect(field(page)).toBeFocused();
};

/**
 * Ask a question and wait for the answer to be ON SCREEN.
 *
 * Waiting on the RESPONSE rather than on a timeout, because the client debounces 200ms and then
 * pays a round trip — a fixed wait would be either flaky or slow. But a response is not a repaint,
 * and that second half matters: a helper that returned on the response let a one-shot `evaluate`
 * read the DOM before Vue had painted the rows, which failed as "expected Künstler, got undefined"
 * under a loaded machine and passed every time in isolation. So it also waits for the panel to be
 * showing something — rows, or one of the notes that stands in for them.
 */
const search = async (page: Page, query: string): Promise<void> => {
    const answered = page.waitForResponse(
        response => response.url().includes("/search?q=") && response.status() === 200
    );
    await field(page).fill(query);
    await answered;
    await expect(page.locator(".search-results__row, .search-results__note").first()).toBeVisible();
};

/**
 * The group KINDS, in the order they are drawn — the fixed group order.
 *
 * Read off the heading's own kind element rather than the whole strip, which also carries the
 * kind's glyph and the total. And with `textContent`, not `innerText`: the strip is
 * `text-transform: uppercase`, which `innerText` faithfully reports as "KÜNSTLER" — an assertion
 * against the word as the catalog spells it then fails looking like a missing translation.
 */
const groupKinds = async (page: Page): Promise<string[]> =>
    (await page.locator(".search-results__kind").allTextContents()).map(text => text.trim());

test.describe("the header search overlay", () => {
    test("opens from the header, answers, and closes on Escape", async ({ page }) => {
        await page.goto("/dashboard");

        await openOverlay(page);
        await search(page, "paranoid");

        const rows = page.locator(".search-results__row");
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText("Paranoid Android");

        await page.keyboard.press("Escape");
        await expect(page.locator(".search-panel")).toBeHidden();
    });

    test("groups a cross-kind answer, containers before contents", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        // "the" is an artist, two albums and a good many songs — so the order is observable and
        // it is not merely one group with everything in it.
        await search(page, "the");

        const kinds = await groupKinds(page);
        expect(kinds[0]).toBe("Künstler");
        expect(kinds).toContain("Alben");
        // Songs always after the containers, whatever else matched.
        expect(kinds.indexOf("Songs")).toBeGreaterThan(kinds.indexOf("Alben"));
    });

    test("finds an accented name from its unaccented spelling", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        await search(page, "ros");

        await expect(page.locator(".search-results__row").first()).toContainText("Sigur Rós");
    });

    test("opens a result and lands on its own page", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);
        await search(page, "paranoid");

        await page.locator(".search-results__row").first().click();

        await expect(pageHeading(page)).toHaveText("Paranoid Android");
        // The panel goes with the reader: a dropdown still hanging over the page they asked for is
        // one more thing to dismiss.
        await expect(page.locator(".search-panel")).toBeHidden();
    });

    /**
     * THE HAND-OFF, which is where the WIDE search lives: the group shows five of many and this
     * link opens the listing that matches artist, album and genre as well — sorted, paginated and
     * deep-linkable, with its own search box already filled in.
     */
    test("hands off to a listing whose own search is filled in", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);
        await search(page, "the");

        const handOff = page.locator(".search-results__row--all").first();
        await expect(handOff).toContainText("Songs");
        await handOff.click();

        await expect(page).toHaveURL(/\/music\/songs\?search=the/u);
        await expect(page.locator(".dt-toolbar__input")).toHaveValue("the");
        await expect(page.locator("tbody tr").first()).toBeVisible();
    });

    /**
     * THE NUMBERS HAVE TO AGREE. The group header says a count and the hand-off promises "all of
     * them"; the listing's own search is wider (title, artist, album, genre), so without
     * `?searchIn=name` the table shows several times what was promised — 70 becoming 2,000+ on a
     * real library. Asserted as the two numbers rather than as the URL,
     * because the URL is the mechanism and this is the contract.
     */
    test("lands on exactly as many rows as the group promised", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);
        await search(page, "the");

        // The songs group's own total, off its count pill.
        const songsGroup = page.locator(".search-results__group", { has: page.locator(".search-results__kind", { hasText: /^Songs$/u }) });
        const promised = Number((await songsGroup.locator(".search-results__count").innerText()).trim());
        expect(promised).toBeGreaterThan(5);

        await songsGroup.locator(".search-results__row--all").click();
        await expect(page).toHaveURL(/searchIn=name/u);

        // "1–25 / TOTAL" — the listing's own count of what it found.
        await expect(page.locator(".dt-pagination__info")).toContainText(`/ ${promised}`);

        // And the way back out to the wide search is offered, and widens it.
        const chip = page.locator(".dt-toolbar__mode");
        await expect(chip).toBeVisible();
        await chip.click();
        await expect(page).not.toHaveURL(/searchIn=name/u);
        await expect(page.locator(".dt-toolbar__mode")).toHaveCount(0);
        // Wider means more: the same query now also matches artist, album and genre.
        await expect(page.locator(".dt-pagination__info")).not.toContainText(`/ ${promised}`);
    });

    test("walks the rows with the arrow keys and opens one with Enter", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);
        await search(page, "queen");

        await page.keyboard.press("ArrowDown");
        // Focus stays in the FIELD — the walk is `aria-activedescendant`, which is what lets the
        // reader keep typing. A row that had taken focus would strand the next character.
        await expect(field(page)).toBeFocused();
        await expect(page.locator(".search-results__row--active")).toHaveCount(1);

        await page.keyboard.press("Enter");
        await expect(page).toHaveURL(/\/music\/(albums|songs)\/[0-9a-f-]{36}/u);
    });

    /**
     * THE CHIPS ARE IN BOTH MOUNTINGS. They started on the Music page
     * alone, where they replaced a search box in each of the four browse widgets — but the two
     * surfaces are one feature and had no business behaving differently, and a reader who learns the
     * chips on /music looks for them here.
     *
     * They appear WITH the results rather than above an empty field, so this asserts both halves.
     */
    test("keeps a row's pips at the trailing edge even when they wrap to their own line", async ({ page }) => {
        /*
         * A row is "name … pips", and a name too long for the line pushes the pips underneath.
         * WHERE THEY GO THEN IS NOT `space-between`'s answer: that packs a line holding one item
         * to the main-START edge, so a wrapped pips line sits flush left under the name. A fact
         * belongs at the trailing edge on every row, so the eye finds it in one column — an auto
         * margin on the pips does the placing on both lines.
         *
         * PINNED BECAUSE THE OLD BEHAVIOUR WAS DELIBERATE and documented as such, right down to a
         * comment saying the auto margin had been tried and removed. Without a test, the next
         * person reading that history would put it back.
         *
         * A narrow viewport, because wrapping is the whole subject: at 380px the fixture's longest
         * artist name ("Jóhann Jóhannsson, Hildur Guðnadóttir & The Theatre of Voices") wraps and
         * the short ones do not, so one query exercises both branches.
         */
        await page.setViewportSize({ width: 380, height: 900 });
        await page.goto("/dashboard");
        await openOverlay(page);
        await search(page, "the");

        const rows = await page.locator(".search-results__row:not(.search-results__row--all)").evaluateAll(nodes =>
            nodes
                .filter(node => node.querySelector(".search-results__pips"))
                .map(node => {
                    const row = node.getBoundingClientRect();
                    const name = node.querySelector(".search-results__name")!.getBoundingClientRect();
                    const pips = node.querySelector(".search-results__pips")!.getBoundingClientRect();

                    return {
                        wrapped: Math.round(pips.top) > Math.round(name.top),
                        fromRight: Math.round(row.right - pips.right)
                    };
                })
        );

        // Both branches are present, or this proves only the easy one.
        expect(rows.some(row => row.wrapped)).toBe(true);
        expect(rows.some(row => !row.wrapped)).toBe(true);

        // Every row's pips end the same distance from the edge — the row's own inline padding.
        expect(new Set(rows.map(row => row.fromRight)).size).toBe(1);
    });

    test("narrows to one kind with a chip, and shows none until there is a query", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        const chips = page.locator(".search-panel [role=radiogroup]");
        await expect(chips).toHaveCount(0);

        await search(page, "queen");
        await expect(chips).toBeVisible();
        // "queen" is both an album and a song in the fixture, so it starts as more than one group.
        expect((await groupKinds(page)).length).toBeGreaterThan(1);

        const narrowed = page.waitForResponse(r => r.url().includes("kinds=album") && r.status() === 200);
        await chips.getByText("Alben", { exact: true }).click();
        await narrowed;

        await expect(page.locator(".search-results__kind")).toHaveCount(1);
        await expect(page.locator(".search-results__kind")).toHaveText("Alben");

        /*
         * The two mountings hold their own question — and this has to be reached by a CLIENT-SIDE
         * visit to mean anything. `page.goto` is a full document load: the JS context is thrown
         * away, so no state could survive it and the assertion would hold against an
         * implementation that shared one module-level box between both search fields. Following a
         * link keeps the context, which is the only way the leak could show.
         */
        await page.getByRole("link", { name: /^Musik$/u }).first().click();
        await page.waitForURL(/\/music$/u);
        await expect(page.locator(".widget-stats__results")).toHaveCount(0);
    });

    test("says nothing was found rather than sitting blank", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        await search(page, "zzzqqq");

        await expect(page.locator(".search-results")).toContainText("nichts in der Sammlung");
        await expect(page.locator(".search-results__row")).toHaveCount(0);
    });

    /** Two characters is not a search — and must not be reported as an empty library. */
    test("asks for a third character before answering anything", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        await field(page).fill("th");

        await expect(page.locator(".search-results")).toContainText("mindestens 3 Zeichen");
        await expect(page.locator(".search-results__row")).toHaveCount(0);
    });

    test("is dismissed by a click outside it", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        // Light dismiss, which the panel gets for free by being a native `[popover]` — the layer
        // it hangs in passes pointer events through, so a press beside it reaches the page.
        await page.mouse.click(5, 400);

        await expect(page.locator(".search-panel")).toBeHidden();
    });
});

test.describe("the keys that open it", () => {
    test("`/` opens the overlay with the caret in the field", async ({ page }) => {
        await page.goto("/dashboard");
        // Nothing may have focus, or the guard would (correctly) stand aside for text entry.
        await page.locator("body").click({ position: { x: 5, y: 5 } });

        await page.keyboard.press("/");

        await expect(page.locator(".search-panel")).toBeVisible();
        await expect(field(page)).toBeFocused();
        // The slash itself must not land in the field it just opened.
        await expect(field(page)).toHaveValue("");
    });

    test("⌘K opens it from anywhere, including out of a text field", async ({ page }) => {
        await page.goto("/music/songs");
        // Focus a real input first: this chord carries a modifier, so unlike `/` it is not the
        // reader's own character and works while they are typing something else.
        await page.locator(".dt-toolbar__input").click();

        await page.keyboard.press("ControlOrMeta+k");

        await expect(page.locator(".search-panel")).toBeVisible();
        await expect(field(page)).toBeFocused();
    });

    /**
     * ASKING AGAIN BRINGS THE CARET BACK. Opening the panel focuses the field, and that always
     * worked — but the flag it hung on cannot change when the panel is ALREADY open, so a reader who
     * had tabbed into the results and pressed ⌘K got nothing at all: measured with focus sitting on
     * a breadcrumb link. A request to search is an event rather than a state, so it travels as a
     * nonce (useSearchOverlay) and every one of the three ways to ask puts the caret back.
     */
    test("brings the caret back when the panel is already open", async ({ page }) => {
        await page.goto("/dashboard");
        await openOverlay(page);

        // Tab out of the field, into the panel.
        await page.keyboard.press("Tab");
        await expect(field(page)).not.toBeFocused();

        await page.keyboard.press("ControlOrMeta+k");
        await expect(field(page)).toBeFocused();

        // …and the bare slash does it too, since by then focus is back in a text field and the
        // guard would otherwise (correctly) stand aside — so tab out again first.
        await page.keyboard.press("Tab");
        await page.keyboard.press("/");
        await expect(field(page)).toBeFocused();
        // The slash that asked must not land in the field it just filled.
        await expect(field(page)).toHaveValue("");
    });

    /** A slash typed into a field is a character, not a shortcut. */
    test("`/` is left alone while the reader is typing somewhere else", async ({ page }) => {
        await page.goto("/music/songs");
        const tableSearch = page.locator(".dt-toolbar__input");
        await tableSearch.click();

        await page.keyboard.press("/");

        await expect(page.locator(".search-panel")).toBeHidden();
        await expect(tableSearch).toHaveValue("/");
    });
});

/**
 * THE ONE THE DESIGN DOC SINGLES OUT. `usePlayerShortcuts` binds Space and `k j l n p m s r q` on
 * the document, so every one of those is a key a reader will type into this field — and the only
 * thing standing between "typing a title" and "the music doing something" is the guard that stands
 * down inside text entry. A constructed event cannot test that; the target is the whole question.
 */
test.describe("typing in the overlay does not drive the player", () => {
    test("a query full of transport keys leaves the player exactly as it was", async ({ page }) => {
        await page.goto("/music/songs");
        await page.locator("tbody tr").first().click();
        await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
        await enqueueFromHero(page);
        await expect(page.locator(".player-bar")).toBeVisible();

        /** What the one <audio> element reports, plus which track it is on. */
        const playerState = () =>
            page.evaluate(() => {
                const audio = document.querySelector("audio")!;

                return { paused: audio.paused, rate: audio.playbackRate, src: audio.getAttribute("src") };
            });

        const before = await playerState();

        await openOverlay(page);
        // Space (play/pause), n/p (track step), s (shuffle), r (repeat), q (queue panel), m (mute),
        // k (play/pause) — every letter of the player's keymap, typed as a person would.
        await page.keyboard.type("smashing pumpkins rock");

        await expect(field(page)).toHaveValue("smashing pumpkins rock");
        expect(await playerState()).toEqual(before);
        // `q` in particular: it is the one shortcut that touches the VIEW, so it would have shown
        // up as a panel sliding over the results.
        await expect(page.locator(".play-queue")).toBeHidden();
    });
});

test.describe("the Music page's own field", () => {
    /**
     * THE ANSWER FLOATS OVER THE CARD, and every clause of that is asserted here because each was a
     * separate failure on the way. It replaced the stat tiles at
     * first, which left the card looking empty mid-search; then it had to escape a card that sets
     * `overflow: hidden` for its rounded title strip AND `isolation: isolate` for its loader, so it
     * became a `[popover]` in the top layer; and a panel free of its container is free to run off
     * the bottom of the window, so its height is capped.
     */
    test("floats the results over the card without disturbing it", async ({ page }) => {
        /*
         * ITS OWN VIEWPORT HEIGHT, and the reason is the point of the test. The project runs at
         * 1280×720, where the CARD ITSELF already ends below the fold — so "extends past the card"
         * and "stays inside the window" cannot both be true there, whatever the panel does. A taller
         * window is the only place the mechanism is observable: the card fits, and a long answer then
         * has room to hang over its bottom border without leaving the screen.
         */
        await page.setViewportSize({ width: 1280, height: 1000 });
        await page.goto("/music");

        const tiles = page.locator(".widget-stats__grid");
        await expect(tiles).toBeVisible();

        const inline = page.locator(".widget-stats .search-field__input");
        const answered = page.waitForResponse(
            response => response.url().includes("/search?q=") && response.status() === 200
        );
        // "the" rather than a query with two answers: the point is that a LONG answer is not cut
        // off by the card, and a panel short enough to fit inside it would pass the geometry
        // assertions below without proving anything.
        await inline.fill("the");
        await answered;
        await expect(page.locator(".widget-stats__results .search-results__row").first()).toContainText("The");

        // The card is untouched: the tiles a reader may only have come to read are still there.
        await expect(tiles).toBeVisible();

        const geometry = await page.evaluate(() => {
            const panel = document.querySelector(".widget-stats__results")!.getBoundingClientRect();
            const card = document.querySelector(".widget")!.getBoundingClientRect();
            const field = document.querySelector(".widget-stats__field")!.getBoundingClientRect();

            return {
                overlapsPastTheCard: panel.bottom > card.bottom,
                insideTheViewport: panel.bottom <= window.innerHeight,
                spansTheField: Math.abs(panel.width - field.width) < 1
            };
        });
        expect(geometry).toEqual({ overlapsPastTheCard: true, insideTheViewport: true, spansTheField: true });

        // Emptying the field is what puts it away — it is `manual`, so nothing else does.
        await inline.fill("");
        await expect(page.locator(".widget-stats__results")).toHaveCount(0);
        await expect(tiles).toBeVisible();
    });

    test("narrows to one kind with a chip", async ({ page }) => {
        await page.goto("/music");

        const inline = page.locator(".widget-stats .search-field__input");
        const answered = page.waitForResponse(response => response.url().includes("/search?q=") && response.status() === 200);
        await inline.fill("queen");
        await answered;

        // Both an album and a song are called "The Queen Is Dead", so this starts with two groups.
        expect((await groupKinds(page)).length).toBeGreaterThan(1);

        const narrowed = page.waitForResponse(response => response.url().includes("kinds=album") && response.status() === 200);
        // Scoped to the WIDGET's group. The overlay mounts the same control, so an unscoped
        // `getByLabel("Suchbereich")` is one open panel away from a strict-mode violation.
        await page.locator(".widget-stats__results [role=radiogroup]").getByText("Alben", { exact: true }).click();
        await narrowed;

        await expect(page.locator(".search-results__heading")).toHaveCount(1);
        await expect(page.locator(".search-results__heading")).toContainText("Alben");
    });
});
