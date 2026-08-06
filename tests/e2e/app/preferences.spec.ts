import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

/*
 * The user menu's colour-scheme switch, in a real engine.
 *
 * Its behaviour — which option is checked, what reaches the meta tag, what is stored — is
 * covered by ThemeSwitch's own Vitest spec. What only a browser can answer is the ARITHMETIC
 * of the shared control it was migrated onto (Components/UI/OptionBubbles, 2026-08-06): the
 * pill's width and offset are a `calc()` over two custom properties, and this is the
 * three-option case. The player's two-option groups exercise the same formula with an even
 * divisor, which is exactly the case that hides a rounding mistake.
 *
 * Sampling has to wait for the popover to finish opening. It scales in, and
 * `getBoundingClientRect` reports transformed boxes — measured mid-animation the group is
 * ~62% of its final width and every number below looks wrong for reasons that have nothing
 * to do with the control.
 */

/** Open the user menu and wait for both the panel and the pill to stop moving. */
const openMenu = async (page: Page): Promise<void> => {
    await page.locator(".user-menu .popover-button").click();
    await expect(page.locator(".user-menu .option-bubbles")).toBeVisible();

    const width = () => page.evaluate(() => document.querySelector(".user-menu .option-bubbles")!.getBoundingClientRect().width);
    let previous = -1;
    await expect
        .poll(async () => {
            const current = await width();
            const settled = current === previous;
            previous = current;

            return settled;
        })
        .toBe(true);
};

/** The pill's box and every option's box, relative to the group. */
const geometry = (page: Page) =>
    page.evaluate(() => {
        const group = document.querySelector(".user-menu .option-bubbles")!.getBoundingClientRect();
        const pill = document.querySelector(".user-menu .option-bubbles__pill")!.getBoundingClientRect();
        const items = [...document.querySelectorAll(".user-menu .option-bubbles__item")].map(node => {
            const box = node.getBoundingClientRect();

            return {
                x: box.x - group.x,
                width: box.width,
                checked: (node.previousElementSibling as HTMLInputElement | null)?.checked === true
            };
        });

        return { groupWidth: group.width, pill: { x: pill.x - group.x, width: pill.width }, items };
    });

test.describe("the colour-scheme switch", () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test("sits its pill exactly over the chosen option, three options in", async ({ page }) => {
        await page.goto("/dashboard");
        await openMenu(page);

        const { groupWidth, pill, items } = await geometry(page);

        // Equal thirds, and the pill is one of them — not "roughly one of them".
        expect(items).toHaveLength(3);
        for (const item of items) expect(item.width).toBeCloseTo(groupWidth / 3, 1);

        const chosen = items.find(item => item.checked)!;
        expect(pill.width).toBeCloseTo(chosen.width, 1);
        expect(pill.x).toBeCloseTo(chosen.x, 1);
    });

    test("moves the pill and the scheme together, and remembers the choice", async ({ page }) => {
        // The half that has to survive the migration: the control drives the meta tag, which
        // is what CSS `light-dark()` keys off, and the choice outlives the tab.
        await page.goto("/dashboard");
        await openMenu(page);

        await page.locator('label[for="theme-dark"]').click();

        await expect
            .poll(() =>
                page.evaluate(() => document.querySelector("meta[name='color-scheme']")?.getAttribute("content"))
            )
            .toBe("dark");

        // POLLED, not read once: the pill slides to its new option, so a single sample right
        // after the click catches it somewhere between the two.
        await expect
            .poll(async () => {
                const { pill, items } = await geometry(page);

                return Math.abs(pill.x - items[0].x) < 0.5;
            })
            .toBe(true);

        await page.reload();
        await openMenu(page);

        expect(await page.evaluate(() => window.localStorage.getItem("theme"))).toBe("dark");
        const restored = await geometry(page);
        expect(restored.items[0].checked).toBe(true);
        expect(restored.pill.x).toBeCloseTo(restored.items[0].x, 1);
    });
});
