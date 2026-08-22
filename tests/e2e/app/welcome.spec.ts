import { expect, test } from "@playwright/test";

/*
 * The landing page as a SIGNED-IN reader sees it — the half `guest/welcome.spec.ts` cannot
 * reach, because that project has no session by construction.
 *
 * `/` is not a guests-only route: the header's logo points here, so a member lands on this page
 * whenever they click it. What they are offered is not a sign-in but the areas themselves, one
 * button each, most-listened first.
 *
 * WHAT ONLY A BROWSER CAN ANSWER, given WelcomeIntro.test.ts already pins which buttons are drawn
 * and in what order:
 *
 *   - THE PAIR FITS AT EVERY WIDTH. Two buttons in a half-width column is a new shape for this
 *     panel — the guest sees one — and the DOM is identical whether they sit side by side or
 *     wrap. Only a measurement can say whether either has been squeezed out of the box holding
 *     them, which is the failure a second button here invites.
 *   - THEY REALLY NAVIGATE. The unit test mounts a mocked <Link>.
 */
test.describe("the landing page for a member", () => {
    test("offers both areas rather than a way to sign in", async ({ page }) => {
        await page.goto("/");

        const buttons = page.locator(".welcome-intro .btn");

        await expect(buttons).toHaveCount(2);
        await expect(page.locator(".welcome-intro")).not.toContainText(/^Anmelden$/u);

        // The seeded library holds both kinds, so both are offered; which comes first depends on
        // this account's listening and belongs to the unit test.
        const hrefs = await buttons.evaluateAll(nodes => nodes.map(node => node.getAttribute("href")));
        expect([...hrefs].sort()).toStrictEqual(["/audiobooks", "/music"]);
    });

    test("keeps both buttons inside their half, at any width", async ({ page }) => {
        /*
         * The invariant, rather than where the wrap falls: a pair of short labels ("Alle Musik",
         * "Alle Hörbücher") still sits side by side at 420px, so asserting that they STACK would
         * be asserting the length of two translations. What must hold at every width is that
         * neither button is squeezed out of the box that holds them — which is the failure a
         * second button in this half invites, and one only a measurement sees.
         */
        for (const width of [1280, 768, 420, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto("/");

            const half = (await page.locator(".welcome-intro__action").boundingBox())!;
            const buttons = await page
                .locator(".welcome-intro .btn")
                .evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect()).map(r => ({ x: r.x, right: r.right })));

            expect(buttons).toHaveLength(2);

            for (const button of buttons) {
                expect(button.x).toBeGreaterThanOrEqual(half.x - 1);
                expect(button.right).toBeLessThanOrEqual(half.x + half.width + 1);
            }
        }
    });

    test("takes a member into the area they picked", async ({ page }) => {
        await page.goto("/");

        await page.locator(".welcome-intro .btn").first().click();

        await expect(page).toHaveURL(/\/(music|audiobooks)$/u);
    });
});
