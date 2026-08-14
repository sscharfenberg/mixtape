import { expect, test } from "@playwright/test";

/*
 * The header's round triggers, from a browser with no session — where there is exactly ONE
 * of them: the user menu. SiteMenu renders nothing for a guest (every area it links to is
 * behind `auth`), and the search and queue toggles hide themselves the same way.
 *
 * WHAT THIS PINS is the fix for a real artefact (2026-08-14). HeaderNavigation gives every
 * non-highlighted trigger a 2px border purely to make the row one height, and that border
 * used to be `transparent` on the theory that the fill shows through it. That holds for a
 * flat colour and NOT for the gradient this button carries: `background-origin` is
 * padding-box while `background-clip` is border-box, so the gradient is sized to the 32×32
 * padding box and REPEATED into the 36×36 border box — the ring above and left painting the
 * tile's bright end, the ring below and right its deep-navy start. It read as a hard dark
 * corner at the top left of an otherwise round button.
 *
 * An opaque ring covers the seam, and the rule is stated as "the ring is the button's own
 * ink" rather than "the ring is white", because that is the same rule `--highlighted`
 * already followed and the one a future variant should inherit. Computed style rather than
 * a screenshot: the alpha is the whole question, and a pixel comparison would fail for
 * twenty reasons that are not this one.
 */

test("the guest user-menu trigger rings itself in its own ink, never transparently", async ({ page }) => {
    await page.goto("/");

    const trigger = page.locator(".user-menu .popover-button");
    await expect(trigger).toBeVisible();

    const style = await trigger.evaluate(el => {
        const computed = getComputedStyle(el);

        return {
            border: computed.borderTopColor,
            ink: computed.color,
            width: computed.borderTopWidth,
            image: computed.backgroundImage
        };
    });

    // the gradient is what makes the transparent ring visible, so the premise is worth stating
    expect(style.image).toContain("linear-gradient");
    expect(style.width).not.toBe("0px");
    expect(style.border).not.toMatch(/rgba\([^)]*,\s*0\)/u);
    expect(style.border).toBe(style.ink);
});
