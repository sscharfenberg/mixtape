import { beforeEach, describe, expect, it } from "vitest";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

/*
 * The other module singleton. Small surface, but it is the contract between every page
 * (which writes a trail in its own setup) and the single Breadcrumb component mounted
 * far away in FullLayout — with no props between them. The tests pin the two properties
 * that relationship depends on: consumers share ONE ref, and setting replaces the trail
 * wholesale rather than appending (main.ts clears it with [] on every navigation, which
 * is what stops a page inheriting the previous page's crumbs).
 */

describe("useBreadcrumbs", () => {
    beforeEach(() => {
        useBreadcrumbs().setBreadcrumbs([]);
    });

    it("starts empty", () => {
        expect(useBreadcrumbs().crumbs.value).toStrictEqual([]);
    });

    it("stores the trail in order", () => {
        const { crumbs, setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
            { label: "Paranoid Android" }
        ]);

        expect(crumbs.value.map(crumb => crumb.label ?? crumb.labelKey)).toStrictEqual([
            "header.siteMenu.music",
            "music.widgets.songs",
            "Paranoid Android"
        ]);
    });

    it("replaces the trail rather than appending to it", () => {
        const { crumbs, setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([{ label: "erste" }, { label: "zweite" }]);
        setBreadcrumbs([{ label: "neue Seite" }]);

        expect(crumbs.value).toHaveLength(1);
        expect(crumbs.value[0].label).toBe("neue Seite");
    });

    it("clears the trail when handed an empty list", () => {
        // This is exactly what main.ts does on every Inertia navigation start.
        const { crumbs, setBreadcrumbs } = useBreadcrumbs();

        setBreadcrumbs([{ label: "vorherige Seite" }]);
        setBreadcrumbs([]);

        expect(crumbs.value).toStrictEqual([]);
    });

    it("hands every caller the same ref, so a page and the layout agree", () => {
        const page = useBreadcrumbs();
        const layout = useBreadcrumbs();

        page.setBreadcrumbs([{ label: "vom Page-Setup" }]);

        expect(layout.crumbs).toBe(page.crumbs);
        expect(layout.crumbs.value[0].label).toBe("vom Page-Setup");
    });
});
