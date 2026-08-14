import { expect, test } from "@playwright/test";

/*
 * The public landing page at `/`, from a browser with no session: the claim, and the two
 * collections' stats cards side by side.
 *
 * WHAT NEEDS A BROWSER HERE IS THE PAIR. "Two widgets of the same width that collapse to one
 * column" is geometry — two boxes' bounding rectangles, at two viewport widths — and it is
 * invisible to every other layer: the DOM is identical at both widths, so a component test
 * asserts only that two cards were rendered. It is also the one part of this page that is a
 * decision rather than a component reused as-is, and the decision is subtle: the cards do NOT
 * take `wide`, because the group's `auto-fit` tracks already collapse the ones no card lands
 * in and hand their width to the `1fr` on the rest. A `wide` card would ask for two tracks and
 * wrap badly at every width that fits three, which is exactly what a screenshot-free assertion
 * about equal widths catches.
 *
 * The second half is the SEARCH FIELD BEING ABSENT. Both cards carry one on their own pages,
 * and `/search` is inside the auth group — so the field that ships by default would be a box
 * answering 401 to everything a visitor typed into it. StatsWidget's unit tests pin the music
 * card's `v-if`; this is where the audiobook card's is covered, and where "no search anywhere
 * on the page" is checked as a fact about the whole page rather than about one component.
 */

test("greets a visitor with the claim, glowing", async ({ page }) => {
    await page.goto("/");

    const heading = page.locator("main h2").first();

    await expect(heading).toHaveText(/Deine Musiksammlung\./u);
    // The glow is a shared class rather than local styling, so the class IS the assertion.
    await expect(heading).toHaveClass(/glowing-border/u);
});

test("shows both collections' totals to a browser with no session", async ({ page }) => {
    await page.goto("/");

    const cards = page.locator(".widget");
    await expect(cards).toHaveCount(2);
    await expect(cards.nth(0).locator(".widget__title")).toHaveText(/Alle Musik/u);
    await expect(cards.nth(1).locator(".widget__title")).toHaveText(/Alle Hörbücher/u);

    // Both cards are six tiles plus a year range, and the range is dropped when nothing on that
    // shelf carries a year — which the randomly re-seeded E2E library cannot promise. So both
    // are asserted as a floor rather than pinned to a number this suite does not control.
    expect(await cards.nth(0).locator(".widget-stats__cell").count()).toBeGreaterThanOrEqual(6);
    expect(await cards.nth(1).locator(".widget-stats__cell").count()).toBeGreaterThanOrEqual(6);
});

test("explains itself before it starts counting, and hands the visitor a way in", async ({ page }) => {
    await page.goto("/");

    const intro = page.locator(".welcome-intro");
    await expect(intro).toBeVisible();
    // The load-bearing half of the copy: a stranger must not go hunting for a sign-up that does
    // not exist, and must know a shared link needs no account at all.
    await expect(intro).toContainText(/nur auf Einladung/u);

    // The button really navigates — the one thing neither the unit test (a mock <Link>) nor
    // `assertInertia` can answer.
    await intro.getByRole("link", { name: /Anmelden/u }).click();
    await expect(page).toHaveURL(/\/login$/u);
});

/*
 * The panel's two halves, which are geometry and therefore live here. The DOM is identical at
 * every width — one wrapping flex row — so nothing but a browser can say whether the button is
 * beside the prose or under it, and nothing but a measurement can say whether "the right half"
 * is actually a half. Both halves grow from the same flex basis, which is what makes the
 * centring mean anything: hand the slack to one side and the button drifts.
 */
test("centres the button on the right half, beside the prose", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/");

    const prose = (await page.locator(".welcome-intro__prose").boundingBox())!;
    const action = (await page.locator(".welcome-intro__action").boundingBox())!;
    const button = (await page.locator(".welcome-intro .btn").boundingBox())!;

    // One row, and two genuine halves. Compared by their CENTRES, not their top edges: the row
    // is `align-items: center`, so a one-line button sitting beside two paragraphs starts ~42px
    // lower than they do — which is the point of centring it, not a misalignment.
    expect(Math.abs((prose.y + prose.height / 2) - (action.y + action.height / 2))).toBeLessThanOrEqual(1);
    expect(action.x).toBeGreaterThan(prose.x + prose.width - 1);
    expect(Math.abs(prose.width - action.width)).toBeLessThanOrEqual(1);

    // The button's middle is its half's middle, not merely somewhere inside it.
    expect(Math.abs(button.x + button.width / 2 - (action.x + action.width / 2))).toBeLessThanOrEqual(1);
});

test("drops the button below the prose on a narrow viewport, still centred", async ({ page }) => {
    await page.setViewportSize({ width: 480, height: 900 });
    await page.goto("/");

    const prose = (await page.locator(".welcome-intro__prose").boundingBox())!;
    const action = (await page.locator(".welcome-intro__action").boundingBox())!;
    const button = (await page.locator(".welcome-intro .btn").boundingBox())!;

    expect(action.y).toBeGreaterThan(prose.y + prose.height - 1);
    expect(Math.abs(button.x + button.width / 2 - (action.x + action.width / 2))).toBeLessThanOrEqual(1);
});

test("offers a guest no search box, on either card or in the header", async ({ page }) => {
    await page.goto("/");

    await expect(page.locator(".widget")).toHaveCount(2);
    await expect(page.locator(".search-field")).toHaveCount(0);
});

test("stands the two cards side by side, at equal width", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/");

    const cards = page.locator(".widget");
    await expect(cards).toHaveCount(2);

    const music = (await cards.nth(0).boundingBox())!;
    const books = (await cards.nth(1).boundingBox())!;

    // One row: same top edge, and the second starts to the right of where the first ends.
    expect(Math.abs(music.y - books.y)).toBeLessThanOrEqual(1);
    expect(books.x).toBeGreaterThan(music.x + music.width - 1);

    // Equal to the pixel, give or take the sub-pixel a fractional `1fr` split leaves behind.
    expect(Math.abs(music.width - books.width)).toBeLessThanOrEqual(1);
});

/*
 * 600px is the width that made WidgetGroup's `pair` variant exist, so it is the width this
 * asserts at. On the group's ordinary floor the two cards were STILL two-up here — about 275px
 * each, in which "6 Stunden, 38 Minuten, 3 Sekunden" wraps to three lines. The floor is in
 * `rem` and the root font-size steps down on small viewports, so it shrinks exactly where a
 * dense card can least afford it; `pair` raises it and moves the collapse to about 800px.
 */
test("stacks them into one column well before the cards get cramped", async ({ page }) => {
    await page.setViewportSize({ width: 600, height: 900 });
    await page.goto("/");

    const cards = page.locator(".widget");
    await expect(cards).toHaveCount(2);

    const music = (await cards.nth(0).boundingBox())!;
    const books = (await cards.nth(1).boundingBox())!;

    // One column: same left edge and the same width, the second below the first.
    expect(Math.abs(music.x - books.x)).toBeLessThanOrEqual(1);
    expect(Math.abs(music.width - books.width)).toBeLessThanOrEqual(1);
    expect(books.y).toBeGreaterThan(music.y + music.height - 1);
});

/*
 * THE TWO CARDS' TILE ROWS LINE UP AND END FLUSH, which is a `WidgetGroup --pair` decision and
 * only observable in a browser.
 *
 * A wrapping tile grid shares its card's SPARE height between its own lines, and the two cards
 * never have the same amount spare: the real library's playtime runs to "2 Monate, 17 Tage, …"
 * and wraps to two lines where the audiobook card's fits on one. Divide what each card has across
 * its own three rows and every tile comes out a different height to its opposite number —
 * measured at 1440px: row 1 drawn 72px tall on the left and 83px on the right, the years row
 * starting 10px lower and the playtime row 22px lower on the right.
 *
 * TWO CLAIMS, AND THE SECOND IS THE ONE A PER-LINE RULE CANNOT MAKE. Rows at their natural height
 * line up, but that leaves the shorter card 32px short at the bottom — a strip of nothing under
 * its playtime. So the BOTTOM row grows instead, and both cards' last tiles end on the same y.
 *
 * THE LONG PLAYTIME IS INJECTED, and that needs justifying. The E2E library is a handful of
 * files, so both playtimes fit on one line here and the rows line up whatever `align-content`
 * says — a test over the seeded data would pass against the bug. What is under test is the CSS,
 * not the numbers, so the fixture the CSS needs is a value long enough to wrap: the same pieces
 * the formatter would emit for a real collection, written into the tile the same way. Growing the
 * seeder to two months of audio to get there would be a far worse trade.
 */
test("keeps both cards' tile rows aligned when one playtime wraps", async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto("/");
    // `goto` resolves on load, which is before Vue has mounted the page — so the cards have to be
    // waited for, or the evaluate below reaches into an empty NodeList.
    await expect(page.locator(".widget-stats__cell").first()).toBeVisible();

    const rows = await page.evaluate(() => {
        const cards = [...document.querySelectorAll(".widget")];

        // Give the MUSIC card the playtime a real library has — five unbreakable pieces, which is
        // what the formatter produces once the total passes a month.
        const cells = cards[0].querySelectorAll(".widget-stats__cell");
        const playtime = cells[cells.length - 1].querySelector(".widget-stats__value")!;
        playtime.innerHTML = ["2 Monate,", "17 Tage,", "6 Stunden,", "38 Minuten,", "3 Sekunden"]
            .map(part => `<span class="widget-stats__part">${part}</span>`)
            .join(" ");

        // Force layout, then read every tile's top edge per card.
        void (cards[0] as HTMLElement).offsetHeight;

        return cards.map(card => {
            const cells = [...card.querySelectorAll(".widget-stats__cell")];
            const last = cells[cells.length - 1];

            return {
                tiles: cells.map(cell => {
                    const box = cell.getBoundingClientRect();

                    return { top: Math.round(box.top), height: Math.round(box.height) };
                }),
                lastBottom: Math.round(last.getBoundingClientRect().bottom),
                // The VALUE's own box, which is where "did it wrap?" is legible: the tile it sits
                // in is sized by the layout under test, so asking the tile would be circular.
                lastValueHeight: Math.round(last.querySelector(".widget-stats__value")!.getBoundingClientRect().height)
            };
        });
    });

    const [music, books] = rows;

    // Same number of tiles on both cards here (six facts plus a year range), so the tops compare
    // one for one.
    expect(books.tiles).toHaveLength(music.tiles.length);
    music.tiles.forEach((cell, index) =>
        expect(Math.abs(cell.top - books.tiles[index].top)).toBeLessThanOrEqual(1));

    // THE PREMISE OF THE CASE, asserted rather than assumed: the injected value really did wrap,
    // so everything below holds in spite of unequal CONTENT and not because there was none.
    // Measured on the value rather than on its tile — 63px against 32px — because the tile's
    // height is the thing under test.
    expect(music.lastValueHeight).toBeGreaterThan(books.lastValueHeight);

    // …and the bottom row absorbed the difference, so the two cards agree all the way down. Both
    // assertions matter: equal heights alone would pass if both tiles were short and the cards
    // ended in matching strips of nothing, and a shared bottom edge is what says the leftover
    // actually went into the tile.
    const lastMusic = music.tiles[music.tiles.length - 1];
    const lastBooks = books.tiles[books.tiles.length - 1];
    expect(Math.abs(lastMusic.height - lastBooks.height)).toBeLessThanOrEqual(1);
    expect(Math.abs(music.lastBottom - books.lastBottom)).toBeLessThanOrEqual(1);
});
