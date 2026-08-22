import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import DashboardPage from "./DashboardPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The dashboard is five settings sections and a jump-nav over them, and the jump-nav is the
 * whole reason this file exists.
 *
 * Its links are `#<id>` anchors declared in THIS file, while the ids they point at are
 * `anchor-id` props declared in five OTHER files. Nothing connects the two but the strings
 * matching. Rename one section's anchor — while renaming a section, say, which is exactly
 * when it happens — and there is no error, no warning, and no visual change: the link is
 * still there, still styled, still clickable, and pressing it does nothing at all. The
 * highlight-the-current-section behaviour stops working too, since useStickyNav observes
 * those same ids.
 *
 * So the test that matters is the round trip: every link resolves to an element that is
 * actually on the page. The rest — ordering and labels — is cheap to assert while the page
 * is mounted, and ordering is worth having because the nav is a map of the page and a nav
 * whose order disagrees with the page's reads as broken.
 *
 * What the sections DO is each their own file's business; here they are only mounted so
 * their anchors exist to be found.
 */

/**
 * Mount the whole dashboard with the props its sections read.
 *
 * `shares` decides whether there are five sections or six: "your shared content" is drawn only
 * for a reader who has shared something, which makes this page the one place both shapes can be
 * compared side by side. Defaulted to the commoner one — most accounts have never pressed
 * "share". The export-presets section is drawn either way, because a preset is a SETTING and
 * this page is where a reader who has never made one meets them.
 */
const dashboard = (shares = false) => {
    setPage({
        props: {
            auth: { user: { name: "Ashaltiriak", email: "ash@example.test" } },
            csrfToken: "token",
            // `hasShares`, not `shares`: a page's own props are merged OVER the shared ones, and
            // `shares` is what Dashboard\SharesController calls its list — so the boolean is
            // named for the question it answers. See HandleInertiaRequests.
            hasShares: shares,
            twoFactorEnabled: false,
            requiresConfirmation: true,
            requiresPasswordConfirmation: false
        }
    });

    return mountApp(DashboardPage, { attachTo: document.body });
};

describe("DashboardPage", () => {
    beforeEach(() => {
        resetInertia();
        // The 2FA section's modal fetches on mount if it ever opens; keep the suite off the
        // network so a real request cannot surface as a post-run ECONNREFUSED.
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({}) }));
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("points every jump-link at a section that is really on the page", () => {
        // The one assertion nothing else can make: these ids live in four other files.
        const wrapper = dashboard();
        const targets = wrapper.findAll(".sticky-nav a").map(link => link.attributes("href")!);

        expect(targets).toHaveLength(5);
        for (const target of targets) {
            expect(wrapper.find(target).exists()).toBe(true);
        }
    });

    it("lists the sections in the order the page renders them", () => {
        const wrapper = dashboard();
        const linked = wrapper.findAll(".sticky-nav a").map(link => link.attributes("href")!.slice(1));
        const rendered = [
            "sharesSection",
            "presetsSection",
            "passwordSection",
            "profileSection",
            "twoFactorSection",
            "deleteSection"
        ].filter(id => wrapper.find(`#${id}`).exists());

        expect(linked).toStrictEqual(rendered);
    });

    describe("the shared-content section", () => {
        it("is absent for a reader who has never shared anything", () => {
            // Which is most of them: a section explaining a feature nobody has used is a
            // section everybody scrolls past.
            const wrapper = dashboard(false);

            expect(wrapper.find("#sharesSection").exists()).toBe(false);
            expect(wrapper.findAll(".sticky-nav a")).toHaveLength(5);
        });

        it("is drawn FIRST for a reader who has, with a jump-link to match", () => {
            // Above the settings sections, because it is not a setting — it is a thing they
            // made that somebody else is holding.
            const wrapper = dashboard(true);
            const targets = wrapper.findAll(".sticky-nav a").map(link => link.attributes("href")!);

            expect(wrapper.find("#sharesSection").exists()).toBe(true);
            expect(targets[0]).toBe("#sharesSection");
            for (const target of targets) expect(wrapper.find(target).exists()).toBe(true);
        });

        it("offers the way to the list rather than the list itself", () => {
            // The dashboard is a signpost here: a section of unknown length in the middle of a
            // settings page would push everything below it out of reach.
            const wrapper = dashboard(true);

            expect(wrapper.find('.form a[href="/dashboard/shared"]').exists()).toBe(true);
            // …and no rows of its own: the list lives at the other end of that link.
            expect(wrapper.find(".shares__row").exists()).toBe(false);
        });
    });

    it("labels each link with its section's own name", () => {
        expect(
            dashboard()
                .findAll(".sticky-nav a")
                .map(link => link.text())
        ).toStrictEqual([
            translate("dashboard.page.nav.presets"),
            translate("dashboard.page.nav.password"),
            translate("dashboard.page.nav.profile"),
            translate("dashboard.page.nav.twoFactor"),
            translate("dashboard.page.nav.delete")
        ]);
    });

    it("heads the page with the dashboard title and one crumb that leads nowhere", () => {
        expect(dashboard().text()).toContain(translate("dashboard.page.title"));
        expect(getLayoutProps().breadcrumbs).toStrictEqual([{ labelKey: "dashboard.page.title", icon: "user-settings" }]);
    });

    it("renders all five settings sections, not just the ones the nav names", () => {
        const wrapper = dashboard();

        expect(wrapper.findComponent({ name: "DashboardPassword" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "DashboardProfile" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "DashboardExportPresets" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "TwoFactor" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "DeleteAccount" }).exists()).toBe(true);
    });

    it("alternates which edge each section's headline tab hugs", () => {
        // Right, left, right, left, right down the page — the section order is what carries it,
        // so a reordering that forgot to re-alternate would put two tabs on the same side. The
        // run starts at `right` so it holds whether or not the conditional shares section (which
        // is `left`) is drawn above it — which is also why that one stays first and stays the
        // only conditional section.
        const wrapper = dashboard();

        expect(
            ["DashboardExportPresets", "DashboardPassword", "DashboardProfile", "TwoFactor", "DeleteAccount"].map(
                name => wrapper.findComponent({ name }).props("align")
            )
        ).toStrictEqual(["right", "left", "right", "left", "right"]);
    });
});
