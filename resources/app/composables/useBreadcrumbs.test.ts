import { beforeEach, describe, expect, it, vi } from "vitest";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { getLayoutProps, resetInertia } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The contract between every page (which declares a trail in its own setup) and the
 * single Breadcrumb component mounted far away in FullLayout, with no props between
 * them. That channel is now an Inertia LAYOUT prop rather than a module-level ref, so
 * what these pin is the shape travelling down it: a page's whole path goes out under
 * the `breadcrumbs` key, and each call replaces the last rather than appending.
 *
 * What is deliberately NOT tested here is the emptying between pages — that is
 * Inertia's `resetLayoutProps()` inside `swapComponent`, not our code, and the reason
 * we moved off the old ref (it clears at the swap, where the ref cleared at request
 * start and left the trail blinking out mid-navigation). The user-visible half of that
 * belongs in Playwright.
 */

/** The trail currently published to the layout, as the layout would receive it. */
const publishedTrail = (): BreadcrumbItem[] | undefined => getLayoutProps().breadcrumbs as BreadcrumbItem[] | undefined;

describe("useBreadcrumbs", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("publishes nothing until a page declares a trail", () => {
        expect(publishedTrail()).toBeUndefined();
    });

    it("publishes the trail in order under the breadcrumbs key", () => {
        const { setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
            { label: "Paranoid Android" }
        ]);

        expect(publishedTrail()?.map(crumb => crumb.label ?? crumb.labelKey)).toStrictEqual([
            "header.siteMenu.music",
            "music.widgets.songs",
            "Paranoid Android"
        ]);
    });

    it("replaces the trail rather than appending to it", () => {
        const { setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([{ label: "erste" }, { label: "zweite" }]);
        setBreadcrumbs([{ label: "neue Seite" }]);

        expect(publishedTrail()).toHaveLength(1);
        expect(publishedTrail()?.[0].label).toBe("neue Seite");
    });

    it("clears the trail when handed an empty list", () => {
        const { setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([{ label: "vorherige Seite" }]);
        setBreadcrumbs([]);

        expect(publishedTrail()).toStrictEqual([]);
    });

    it("writes to one store, so a page and the layout agree", () => {
        const page = useBreadcrumbs();
        const layout = useBreadcrumbs();

        page.setBreadcrumbs([{ label: "vom Page-Setup" }]);
        layout.setBreadcrumbs([{ label: "vom Layout" }]);

        expect(publishedTrail()?.[0].label).toBe("vom Layout");
    });
});
