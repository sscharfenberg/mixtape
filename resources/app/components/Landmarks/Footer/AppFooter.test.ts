import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import AppFooter from "./AppFooter.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The footer line, which is assembled entirely on the CLIENT and so cannot be checked by
 * `assertInertia`: the server sends a version string and nothing else, and the year, the
 * catalogue line and the choice between its two forms all happen here.
 *
 * `tests/Feature/AppVersionTest.php` owns the other half — that the prop is shared at all,
 * and that it comes out of package.json. This file owns what is done with it.
 */

/** Mount the footer with a given shared `version` prop. */
const footer = (version: string | null = "2.0.0") => {
    setPage({ props: { auth: { user: null }, csrfToken: "test-token", version } });

    return mountApp(AppFooter);
};

describe("AppFooter", () => {
    beforeEach(() => {
        resetInertia();
        // A MOCKED CLOCK IS TEST STATE, and two of these move it. Restored here rather than
        // in an afterEach so a spec that throws cannot leave the next one living in 2031 —
        // the same reason the queue's own reset drains its pending flush.
        vi.useRealTimers();
    });

    it("prints the version the server sent, prefixed with a v", () => {
        // The "v" lives in the catalogue line, not in the value: the server shares what
        // package.json says, and how it is spelled is the footer's business.
        expect(footer("9.8.7").text()).toContain("v9.8.7");
    });

    it("drops the version AND its separator when the server could not read one", () => {
        /*
         * The whole reason there are two catalogue lines rather than one with an optional
         * tail: the version sits after a middle dot, so an empty interpolation would leave
         * the footer reading "2026 · " and trailing into nothing.
         */
        const text = footer(null).text().replace(/\s+/gu, " ");

        expect(text).not.toContain("·");
        expect(text).toContain("2026");
    });

    it("shows the launch year alone until a later year rolls around", () => {
        // The range only opens once there is something to range over — "2026 - 2026" is a
        // date nobody writes.
        vi.setSystemTime(new Date("2026-11-30T12:00:00Z"));

        expect(footer().text()).toContain("2026");
        expect(footer().text()).not.toContain("2026 - ");
    });

    it("widens to a range once the year has moved on", () => {
        vi.setSystemTime(new Date("2031-02-01T12:00:00Z"));

        expect(footer().text().replace(/\s+/gu, " ")).toContain("2026 - 2031");
    });

    it("links to the source, in a new tab and without passing rank to it", () => {
        // An external link's two attributes are the whole safety story, and they come from
        // LabelledLink's own https branch rather than from anything set here.
        const link = footer().find("a[href^='https://github.com']");

        expect(link.exists()).toBe(true);
        expect(link.attributes("target")).toBe("_blank");
        expect(link.attributes("rel")).toContain("noopener");
    });

    it("shows the mark alone, but is still NAMED for anyone who cannot see it", () => {
        /*
         * The link is icon-only and keeps its accessible name. An icon-only link is
         * unlabelled to a screen reader — the glyph is a `<use>`, not text — so the label
         * moved to `aria-label`. This is the assertion that stops the word being deleted
         * outright by someone tidying up.
         */
        const link = footer().find("a[href^='https://github.com']");

        expect(link.text()).toBe("");
        expect(link.attributes("aria-label")).toBeTruthy();
        expect(link.find("use").attributes("href")).toContain("github");
    });
});
