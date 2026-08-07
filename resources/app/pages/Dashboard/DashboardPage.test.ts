import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import DashboardPage from "./DashboardPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The dashboard is four settings sections and a jump-nav over them, and the jump-nav is the
 * whole reason this file exists.
 *
 * Its links are `#<id>` anchors declared in THIS file, while the ids they point at are
 * `anchor-id` props declared in four OTHER files. Nothing connects the two but the strings
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

/** Mount the whole dashboard with the props its four sections read. */
const dashboard = () => {
    setPage({
        props: {
            auth: { user: { name: "Ashaltiriak", email: "ash@example.test" } },
            csrfToken: "token",
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

        expect(targets).toHaveLength(4);
        for (const target of targets) {
            expect(wrapper.find(target).exists()).toBe(true);
        }
    });

    it("lists the sections in the order the page renders them", () => {
        const wrapper = dashboard();
        const linked = wrapper.findAll(".sticky-nav a").map(link => link.attributes("href")!.slice(1));
        const rendered = ["passwordSection", "profileSection", "twoFactorSection", "deleteSection"].filter(
            id => wrapper.find(`#${id}`).exists()
        );

        expect(linked).toStrictEqual(rendered);
    });

    it("labels each link with its section's own name", () => {
        expect(
            dashboard()
                .findAll(".sticky-nav a")
                .map(link => link.text())
        ).toStrictEqual([
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

    it("renders all four settings sections, not just the ones the nav names", () => {
        const wrapper = dashboard();

        expect(wrapper.findComponent({ name: "DashboardPassword" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "DashboardProfile" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "TwoFactor" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "DeleteAccount" }).exists()).toBe(true);
    });

    it("alternates which edge each section's headline tab hugs", () => {
        // Right, left, right, left down the page — the section order is what carries it, so a
        // reordering that forgot to re-alternate would put two tabs on the same side.
        const wrapper = dashboard();

        expect(
            ["DashboardPassword", "DashboardProfile", "TwoFactor", "DeleteAccount"].map(
                name => wrapper.findComponent({ name }).props("align")
            )
        ).toStrictEqual(["right", "left", "right", "left"]);
    });
});
