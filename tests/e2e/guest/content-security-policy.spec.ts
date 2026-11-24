import { expect, test } from "../support/test";

/******************************************************************************
 * The precondition the CSP guard (support/test.ts) rests on: that the server
 * really ENFORCES a nonce policy. If the run fell back to report-only — a lost
 * CSP_ENFORCE in support/environment.ts, a middleware no longer registered —
 * no page would fail to load and the guard would watch nothing, every spec
 * green. And the one inline script, the pre-paint theme snippet, must still
 * RUN under it: a nonce the snippet no longer carries is invisible on a page
 * whose theme happens to match the OS.
 *****************************************************************************/

test("the server enforces a nonce policy that allows no inline script", async ({ page }) => {
    const response = await page.goto("/login");
    const policy = response?.headers()["content-security-policy"] ?? "";

    expect(policy, "the enforcing header (not -Report-Only)").not.toBe("");
    expect(policy).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9]+'(;|$)/u);
    expect(policy.split("; ").find(d => d.startsWith("script-src"))).not.toContain("unsafe-inline");
});

test("the nonced theme snippet runs under the enforced policy", async ({ page }) => {
    // The snippet copies a stored theme into <meta name="color-scheme"> before first paint.
    // Seed the opposite of the template's default, so only a snippet that RAN can produce it.
    await page.addInitScript(() => localStorage.setItem("theme", "dark"));

    await page.goto("/login");

    await expect(page.locator('meta[name="color-scheme"]')).toHaveAttribute("content", "dark");
});
