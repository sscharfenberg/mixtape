import { expect, test } from "@playwright/test";
import { pageHeading, stopQueueSync } from "../support/actions";
import { clearServerQueue, specStorageState } from "../support/environment";

/*
 * The Music page's browse widgets: four cards of entries, each a link to the thing it
 * names with its facts as icon pips — plus, since 2026-08-13, the stats card's tiles,
 * which are here for the reason the whole file is rather than because they are a list.
 *
 * The layout guards below are what needs a browser. Everything about them — `min-width`
 * on a grid or flex item, `text-overflow` firing, a box containing its own content — is
 * geometry, invisible to a DOM assertion and to happy-dom alike. All three so far have
 * been the same bug wearing different clothes: a run of text that cannot break, in a box
 * that was allowed to shrink below it.
 */

/*
 * ITS OWN ACCOUNT, AND A CLEAN QUEUE PER TEST. The play queue is server state since the
 * `player_states` sync landed, so a fresh browser context is no longer a fresh player: a
 * queue follows the USER. Sharing one account across files let a spec in one worker restore
 * a queue another worker had just left, and sharing it across tests in this file let each
 * test inherit the last one's. The account is this file's alone (E2ESeeder seeds it,
 * auth.setup mints its session) and the reset below is what tests here owe each other.
 */
test.use({ storageState: specStorageState("widgets") });

/*
 * SEQUENTIAL, IN ONE WORKER, which the account above is worthless without. `fullyParallel`
 * parallelises at the TEST level, not the file level — so without this the tests in this
 * file run CONCURRENTLY against the one account they share, and each sees the others'
 * queues. That failed as a count one too high, on a different test every run.
 */
test.describe.configure({ mode: "default" });

test.beforeEach(async () => {
    await clearServerQueue("widgets");
});

// The other half of the isolation: a tab flushes its queue as it closes, with `keepalive`,
// so that request can outlive the test and land after the NEXT one has reset the account.
test.afterEach(async ({ page }) => {
    await stopQueueSync(page);
});

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
        await expect(pageHeading(page)).toHaveText(name);
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

    test("clips a long pip value instead of letting it out of the entry", async ({ page }) => {
        /*
         * The same contract as the entry name above, one level down — and it was NOT holding.
         * Reported from the field: at 920px with the play queue open, an album credited to
         * "Electric Callboy, Electric Bassboy, Paolo Ferrara" ran straight out of its card.
         *
         * Two of the four widgets pip a NAME (albums and songs both pip the artist). The pip
         * is `white-space: nowrap`, so with the flex item's default `min-width: auto` it could
         * not shrink below that one unbreakable run: it pushed out of the entry's filled block
         * and was hard-clipped by the card's `overflow: hidden` — cut mid-word, no ellipsis,
         * at an edge nothing on screen explains. Measured before the fix: a 306px pip inside a
         * 230px row, its right edge 46px past the card's.
         *
         * HOW THE VALUE IS MADE TOO LONG CHANGED, and the note it replaces is worth keeping in
         * view. It used to read "both halves of the reported condition are load-bearing … it is
         * the queue taking a column that makes the card ~290px", because at 920px with no queue
         * two ~430px cards fit and the reported credit did not overflow one. The queue stopped
         * taking a column on 2026-08-08 (it overlays at every width now — see PlayQueue), so
         * that lever is gone and the test went green against nothing.
         *
         * The subject was never the queue, though: it is a value too long for its pip. So the
         * injected string is now unambiguously too long for any card this layout produces,
         * which makes the test independent of how the content column happens to be sized. The
         * assertions are unchanged and still RELATIONAL — the pip stays inside its entry, the
         * card does not grow — so they keep meaning at whatever width a future layout picks.
         */
        await page.setViewportSize({ width: 920, height: 900 });

        await page.goto("/music");
        await expect(page.locator(".widget-list__item").first()).toBeVisible();

        const measured = await page.evaluate(() => {
            // A pip carrying a name, not a count — the albums and songs widgets have them.
            const value = [...document.querySelectorAll<HTMLElement>(".widget-list__pip-value")].find(node =>
                /[A-Za-z]{4,}/u.test(node.textContent ?? "")
            )!;
            const pip = value.closest(".widget-list__pip") as HTMLElement;
            const item = pip.closest(".widget-list__item") as HTMLElement;
            const card = pip.closest(".widget") as HTMLElement;
            const widthBefore = card.getBoundingClientRect().width;

            // The reported credit, extended until it cannot fit any card this layout makes —
            // see the note above for why the width no longer comes from the queue.
            value.textContent =
                "Electric Callboy, Electric Bassboy, Paolo Ferrara, Giacomo Rossi, Annika Lindqvist-Bergström";

            return {
                valueClipped: value.scrollWidth > value.clientWidth,
                pipRight: Math.round(pip.getBoundingClientRect().right),
                itemRight: Math.round(item.getBoundingClientRect().right),
                itemOverflow: item.scrollWidth - item.clientWidth,
                widthBefore: Math.round(widthBefore),
                widthAfter: Math.round(card.getBoundingClientRect().width)
            };
        });

        // Clipped, so the ellipsis has something to act on...
        expect(measured.valueClipped).toBe(true);
        // ...the pip stays inside the entry's filled block rather than escaping it...
        expect(measured.pipRight).toBeLessThanOrEqual(measured.itemRight);
        expect(measured.itemOverflow).toBe(0);
        // ...and the card is exactly the width it was.
        expect(measured.widthAfter).toBe(measured.widthBefore);
    });

    test("keeps a stat tile's padding under a value that cannot wrap", async ({ page }) => {
        /*
         * The third layout guard, and the same shape as the two above: a run of text that cannot
         * break, inside a box that was allowed to shrink below it.
         *
         * Reported from the field at 1600px — "Dateigröße / 83,27 GB" with no padding visible on
         * either side. The tiles are `flex: 1 1 7rem` and each value became ONE unbreakable span on
         * 2026-08-13 (a number and its unit are one word to a reader), so `min-width: 0` — correct
         * while the values could reflow onto two lines — let a tile shrink under its own text.
         * Measured before the fix: a 122px tile whose value needed 123px, insets 0 and 0, its own
         * `scrollWidth` past its `clientWidth`. After: 139px, insets 8 and 8, nothing overflowing.
         *
         * RELATIONAL, like its neighbours: the inset is compared against the tile's OWN computed
         * padding rather than against 8px, so the assertion survives a change to the token. The
         * value is injected for the same reason the credit above is — the E2E library is small
         * enough to print a short size, and this must measure the case that was reported.
         */
        await page.setViewportSize({ width: 1600, height: 900 });
        await page.goto("/music");
        await expect(page.locator(".widget-stats__cell").first()).toBeVisible();

        const measured = await page.evaluate(() => {
            const cells = [...document.querySelectorAll<HTMLElement>(".widget-stats__cell")];
            const size = cells.find(cell => cell.querySelector(".widget-stats__label")?.textContent === "Dateigröße")!;
            size.querySelector<HTMLElement>(".widget-stats__part")!.textContent = "83,27 GB";

            return cells.map(cell => {
                const box = cell.getBoundingClientRect();
                const value = cell.querySelector<HTMLElement>(".widget-stats__value")!.getBoundingClientRect();

                return {
                    label: cell.querySelector(".widget-stats__label")?.textContent ?? "?",
                    padding: Number.parseFloat(getComputedStyle(cell).paddingLeft),
                    insetLeft: Math.round(value.left - box.left),
                    insetRight: Math.round(box.right - value.right),
                    overflow: cell.scrollWidth - cell.clientWidth
                };
            });
        });

        for (const tile of measured) {
            expect(tile.insetLeft, `${tile.label} keeps its leading padding`).toBeGreaterThanOrEqual(tile.padding);
            expect(tile.insetRight, `${tile.label} keeps its trailing padding`).toBeGreaterThanOrEqual(tile.padding);
            expect(tile.overflow, `${tile.label} contains its value`).toBe(0);
        }
    });
});
