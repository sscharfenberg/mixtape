import { expect, test } from "@playwright/test";

/*
 * The Music page's browse widgets: four cards of entries, each a link to the thing it
 * names with its facts as icon pips.
 *
 * The layout guard below is the one that needs a browser. Everything about it —
 * `min-width: 0` on a grid item, `text-overflow` firing, a card containing its own
 * content — is geometry, invisible to a DOM assertion and to happy-dom alike.
 */

test.describe("the music widgets", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/music");
        await expect(page.locator(".widget-list__item").first()).toBeVisible();
    });

    test("makes every entry a link carrying its facts as pips", async ({ page }) => {
        const entry = page.locator(".widget-list__item").first();

        await expect(entry).toHaveAttribute("href", /\/music\/(albums|artists|genres|songs)\//u);
        // Icon pips, not written labels: each pip shows a glyph and a value.
        const pip = entry.locator(".widget-list__pip").first();
        await expect(pip.locator("svg")).toBeVisible();
        await expect(pip).not.toBeEmpty();
    });

    test("opens the entry's own page when clicked", async ({ page }) => {
        const entry = page.locator(".widget-list__item").first();
        const name = await entry.locator(".widget-list__name").innerText();
        const href = await entry.getAttribute("href");

        await entry.click();

        await page.waitForURL(new RegExp(`${href}$`, "u"));
        await expect(page.locator("main h1").first()).toHaveText(name);
    });

    test("explains a pip through its tooltip, since the icon carries no words", async ({ page }) => {
        // The accessibility half of the pip design: the label lives only in the tip, so
        // losing the tip would leave a grid of unexplained glyphs.
        const pip = page.locator(".widget-list__pip").first();

        await pip.hover();

        const tip = page.locator("#app-tooltip");
        await expect(tip).toBeVisible();
        // "<label>: <value>" — the label is the part that is never on screen otherwise.
        await expect(tip).toContainText(":");
    });

    test("keeps the card exactly its height while a refresh is in flight", async ({ page }) => {
        /*
         * The whole point of a skeleton: nothing moves. The placeholder used to be four
         * plain 14px bars standing in for four 65px entries, so every refresh collapsed the
         * card to a third of its height and snapped it back — worse than showing nothing.
         *
         * The refresh response is held open so the skeleton can actually be measured; it
         * finishes on its own once the route is released at the end of the test.
         */
        const card = page.locator(".widget").nth(1);
        const before = await card.evaluate(node => Math.round(node.getBoundingClientRect().height));

        await page.route("**/music**", async route => {
            await new Promise(resolve => setTimeout(resolve, 2000));
            await route.continue();
        });
        await card.getByRole("button").first().click();

        const skeleton = card.locator(".widget-skeleton");
        await expect(skeleton).toBeVisible();
        // It is the entry-shaped variant, not the prose bars.
        await expect(card.locator(".widget-skeleton__entry").first()).toBeVisible();

        const during = await card.evaluate(node => Math.round(node.getBoundingClientRect().height));
        expect(during).toBe(before);
    });

    test("clips a long entry name instead of letting it widen the card", async ({ page }) => {
        /*
         * The name is injected rather than seeded, deliberately: this asserts a CSS
         * contract, not a query, and no fixture title is long enough to exercise it. A
         * real one that is — "String Quartet in G Minor, Op. 32 No. 5: IV. Allegro giusto".
         *
         * The bug this guards was NOT the ellipsis rule going missing. It was `.widget__body`
         * being a grid item with the default `min-width: auto`, whose min-content size is
         * that unbreakable line — so the body grew past the card, the name's own box grew
         * with it, and `text-overflow` never fired because nothing was overflowing. Hence
         * both assertions: the name must be clipped AND the card must contain its content.
         */
        const measured = await page.evaluate(() => {
            const name = document.querySelector(".widget-list__name") as HTMLElement;
            const card = name.closest(".widget") as HTMLElement;
            const widthBefore = card.getBoundingClientRect().width;

            name.textContent = "String Quartet in G Minor, Op. 32 No. 5: IV. Allegro giusto";

            return {
                widthBefore: Math.round(widthBefore),
                widthAfter: Math.round(card.getBoundingClientRect().width),
                nameClipped: name.scrollWidth > name.clientWidth
            };
        });

        // Clipped, so `text-overflow` has something to act on...
        expect(measured.nameClipped).toBe(true);
        // ...and the card is exactly the width it was, so the title did not push it wider.
        //
        // Measured on the SAME card before and after rather than against an absolute
        // "nothing overflows": every list widget carries about 11px of harmless horizontal
        // overflow in its shell, present with or without this title and hidden by the card's
        // own clip, so an absolute assertion would fail for a reason unrelated to the bug.
        expect(measured.widthAfter).toBe(measured.widthBefore);
    });
});
