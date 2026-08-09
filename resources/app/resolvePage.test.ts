import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { resolvePage } from "./resolvePage";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * How a page is resolved, and the one rule that resolution applies.
 *
 * THE WARNING THIS EXISTS FOR is development-only, which is exactly why it is pinned here rather
 * than in a browser: `npm run build` strips `[Vue warn]`, and the E2E harness deliberately runs
 * against a production bundle (it tests what ships), so no Playwright spec can ever see it.
 * Vitest runs Vue's dev build, so this is the only layer that can.
 *
 * Inertia hands every page the SHARED props as well as its own, a page declares only what it uses,
 * and the rest arrive as fallthrough attrs — none of which is an HTML attribute. Every page here
 * renders a fragment (a `<Head>` beside a `<container>`), so Vue cannot inherit them onto a single
 * root and says so once per render. On the Now Playing page, which re-renders on every track
 * change, that is a stream of warnings rather than one stale line.
 */

/** Capture `console.warn` for the duration of one mount. */
const warningsDuring = (mountIt: () => void): string[] => {
    const seen: string[] = [];
    const spy = vi.spyOn(console, "warn").mockImplementation((...args: unknown[]) => {
        seen.push(args.map(String).join(" "));
    });

    mountIt();
    spy.mockRestore();

    return seen;
};

/** A page-shaped component: two roots, exactly like every page in this app. */
const fragmentPage = () =>
    defineComponent({
        name: "FragmentPage",
        render: () => [h("span"), h("div", "content")]
    });

afterEach(() => {
    vi.restoreAllMocks();
});

describe("resolvePage", () => {
    it("switches attribute fallthrough off on the page it resolves", async () => {
        // The rule itself, applied at the one seam every page comes through — so a new page cannot
        // forget it and twenty-odd files need no `defineOptions` of their own.
        const page = await resolvePage("NowPlaying/NowPlayingPage");

        expect(page.inheritAttrs).toBe(false);
    });

    it("refuses a name it has no page for, rather than resolving undefined", async () => {
        await expect(resolvePage("Nowhere/NoSuchPage")).rejects.toThrow("Page not found");
    });

    describe("the warning it prevents", () => {
        it("is real: a fragment root with fallthrough attrs warns", () => {
            // Pinning the MECHANISM, so the rule above cannot quietly stop mattering — if Vue ever
            // inherits attrs onto a fragment, this test says so and the workaround can go.
            const warnings = warningsDuring(() => {
                mount(fragmentPage(), { attrs: { csrfToken: "abc", locale: "de" } });
            });

            expect(warnings.join(" ")).toContain("Extraneous non-props attributes");
        });

        it("is silenced by the flag resolvePage sets", () => {
            const quiet = fragmentPage();
            quiet.inheritAttrs = false;

            const warnings = warningsDuring(() => {
                mount(quiet, { attrs: { csrfToken: "abc", locale: "de" } });
            });

            expect(warnings.join(" ")).not.toContain("Extraneous non-props attributes");
        });
    });
});
