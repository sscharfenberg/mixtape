import { expect, test } from "@playwright/test";
import { settled } from "../support/actions";

/*
 * The hero's metadata row — one rule, and a bug that hid inside a CSS selector.
 *
 * EVERY TILE IS PAINTED THE SAME, and a `> :slotted(*)` selector cannot guarantee it. Vue puts
 * the slotted marker (`data-v-…-s`) on the ROOT of what a slot was handed, and `PlayCountFacts`
 * is a MULTI-ROOT component — two `FactPair`s in a fragment — so its tiles never carry the
 * marker, the selector cannot see them, and the "Von dir" / "Von anderen" tiles render with no
 * halo beside tiles that have one, on seven pages. Measured: `box-shadow: none` against
 * `rgba(0, 124, 184, 0.4) 0 0 8px`.
 *
 * WHY IT NEEDS A BROWSER, and could not have been caught anywhere else. The whole failure is a
 * selector not matching, so the only thing that can answer it is a real engine resolving a real
 * stylesheet against a real tree — Vitest applies no scoped styles and would have reported the
 * same DOM either way. It is also why the assertions read computed values rather than compare
 * pixels: what is being pinned is "these are painted alike", not any particular glow.
 *
 * AND WHY THE FIXTURE SEEDS PLAYS. `PlayCountFacts` hides a zero, so on a library nobody has
 * listened to its tiles never render at all — which is precisely why nothing noticed. E2ESeeder
 * puts two listens on "Karma Police" so the pair is reliably on screen; see its note on why no
 * number here is safe to assert.
 */

test.use({ viewport: { width: 1440, height: 900 } });

/** Every metadata tile's paint and flex behaviour, with enough text to name it in a failure. */
const tiles = (page: import("@playwright/test").Page) =>
    page.locator(".hero-section__metadata .fact-pair").evaluateAll(nodes =>
        nodes.map(node => ({
            label: (node.textContent ?? "").replace(/\s+/gu, " ").trim().slice(0, 24),
            shadow: getComputedStyle(node).boxShadow,
            grow: getComputedStyle(node).flexGrow
        }))
    );

test("paints every tile in the row alike, including the ones a nested component renders", async ({ page }) => {
    await page.goto("/music/songs?search=Karma%20Police");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/songs\/[0-9a-f-]{36}/u);
    await settled(page);

    const row = await tiles(page);

    // The two play tiles are the point of this test; without them it proves nothing.
    expect(row.map(entry => entry.label)).toEqual(expect.arrayContaining([expect.stringContaining("Von dir")]));
    expect(row.map(entry => entry.label)).toEqual(expect.arrayContaining([expect.stringContaining("Von anderen")]));

    for (const entry of row) {
        expect(entry.shadow, `no halo on "${entry.label}"`).not.toBe("none");
    }
});

test("lets the tiles fill the row, so it never ends in a bare stripe", async ({ page }) => {
    /*
     * The row grows for every page rather than behind a per-page `growMetadata` prop. Asserted as
     * `flex-grow` rather than by measuring the row's right
     * edge: a row of tiles that happens to fill 1440px is not the same claim as a row that WILL,
     * and the measurement would move with every fixture change.
     */
    await page.goto("/music/albums");
    await page.locator("tbody tr").first().click();
    await page.waitForURL(/\/music\/albums\/[0-9a-f-]{36}/u);
    await settled(page);

    const row = await tiles(page);

    expect(row.length).toBeGreaterThan(1);
    for (const entry of row) {
        expect(entry.grow, `"${entry.label}" does not grow`).toBe("1");
    }
});

/*
 * THE PANEL KEEPS ITS OWN LIGHT IN. Two things in the hero glow past
 * their own boxes — every metadata tile's halo, asserted above, and the neon `.btn` spread on the
 * actions row. On a phone the tiles reach the panel's padding and the glow washes over the
 * rotating gradient ring, which reads as the border being broken.
 *
 * ASSERTED AS A COMPUTED VALUE, not as a screenshot, and for a harder reason than the spec above
 * gives: that ring is ANIMATED — a conic gradient sweeping one turn — so two runs of the same page
 * differ pixel-for-pixel by construction and no image comparison can be stable. A bounding box
 * cannot help either, since a `box-shadow` contributes nothing to layout: the glow that escapes is
 * invisible to every geometric measurement. What is left is the declaration itself, which is also
 * exactly what the fix is.
 *
 * `clip` RATHER THAN `hidden` IS THE PART WORTH PINNING. `hidden` would make the panel a scroll
 * container, so focusing a control near its edge would scroll it and reveal what was meant to stay
 * clipped; `clip` cannot scroll at all. A well-meant tidy-up to `hidden` is the regression this
 * case exists to catch, so the value is asserted exactly rather than as "not visible".
 */
test("clips its own glow to the panel, without becoming scrollable", async ({ page }) => {
    // The href is collected at the file's own width and the page opened directly at 400px,
    // rather than resizing and then clicking: below `landscape` the DataTable swaps its rows for
    // cards, so the row this file clicks everywhere else is present but not clickable there.
    await page.goto("/music/songs?search=Karma%20Police");
    await page.locator("tbody tr").first().click();
    // `waitForURL`, not `settled` then `page.url()`: settling does not guarantee the visit has
    // landed, so the url read back was still the listing's and the reload below re-opened it.
    await page.waitForURL(/\/music\/songs\/[\w-]+$/u);
    const url = page.url();

    await page.setViewportSize({ width: 400, height: 900 });
    await page.goto(url);
    await settled(page);

    const hero = page.locator(".hero-section");
    await expect(hero).toBeVisible();

    const box = await hero.evaluate(el => ({
        overflow: getComputedStyle(el).overflowX,
        // The other half of choosing `clip`: no scrollport, so nothing can be scrolled into view.
        overflowing: el.scrollWidth > el.clientWidth || el.scrollHeight > el.clientHeight
    }));

    expect(box.overflow).toBe("clip");
    expect(box.overflowing).toBe(false);
});
