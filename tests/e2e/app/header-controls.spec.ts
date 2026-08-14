import { expect, test } from "@playwright/test";

/*
 * The header's round triggers, signed in — where all three ring variants are on screen at
 * once and can be checked against each other. The guest half of this pair
 * (tests/e2e/guest/header-controls.spec.ts) carries the full story of the seam that put
 * these tests here; the short version is that a `transparent` ring shows a repeated
 * gradient TILE rather than the fill, so a gradient trigger needs an opaque one.
 *
 * The rule the header now follows, and the reason both halves of it are worth pinning:
 *
 *   - a gradient trigger (SiteMenu below desktop, and the guest user menu) rings itself in
 *     its own ink, because a transparent ring would show the seam;
 *   - `--highlighted` already did exactly that with its own colours, and must keep doing it —
 *     the new rule excludes it, and an exclusion that stops matching repaints it;
 *   - `--subtle` deliberately keeps the transparent ring: a flat fill has no tile and no seam,
 *     and a ring on the quiet variant would undo the point of it being quiet.
 *
 * A narrow viewport on purpose: SiteMenu shows its inline link row from desktop up and the
 * popover trigger only below it, and the popover trigger is the gradient one.
 */

test.use({ viewport: { width: 620, height: 700 } });

/** Read the three facts that decide how a trigger's ring is painted. */
const ring = (page: import("@playwright/test").Page, selector: string) =>
    page.locator(selector).evaluate(el => {
        const computed = getComputedStyle(el);

        return {
            border: computed.borderTopColor,
            ink: computed.color,
            box: Math.round(el.getBoundingClientRect().height)
        };
    });

test("each header trigger rings itself in its own ink, except the quiet one", async ({ page }) => {
    await page.goto("/music/songs");

    const siteMenu = page.locator(".site-menu .popover-button");
    await expect(siteMenu).toBeVisible();
    await expect(page.locator(".search-toggle")).toBeVisible();

    const gradient = await ring(page, ".site-menu .popover-button");
    const highlighted = await ring(page, ".user-menu .popover-button");
    const subtle = await ring(page, ".search-toggle");

    expect(gradient.border).toBe(gradient.ink);
    expect(highlighted.border).toBe(highlighted.ink);
    expect(subtle.border).toMatch(/rgba\([^)]*,\s*0\)/u);

    // the border is there to make the row ONE height (HeaderNavigation's own comment says why
    // a border rather than a min-height) — so all three must still measure the same.
    expect(highlighted.box).toBe(gradient.box);
    expect(subtle.box).toBe(gradient.box);
});
