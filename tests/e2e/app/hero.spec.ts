import { expect, test } from "@playwright/test";
import { settled } from "../support/actions";

/*
 * The hero's metadata row — one rule, and a bug that hid inside a CSS selector.
 *
 * EVERY TILE IS PAINTED THE SAME, and until 2026-08-14 two of them were not. HeroSection styled
 * its tiles through `> :slotted(*)`, and Vue puts the slotted marker (`data-v-…-s`) on the ROOT
 * of what a slot was handed. `PlayCountFacts` is a MULTI-ROOT component — two `FactPair`s in a
 * fragment — so its tiles never carried the marker, the selector could not see them, and the
 * "Von dir" / "Von anderen" tiles rendered with no halo beside tiles that had one. On seven
 * pages. Measured before the fix: `box-shadow: none` against `rgba(0, 124, 184, 0.4) 0 0 8px`.
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
     * The owner's call, 2026-08-14, replacing a per-page `growMetadata` prop that only the guest
     * share page had turned on. Asserted as `flex-grow` rather than by measuring the row's right
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
