import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ForgotPage from "./ForgotPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * One form recovering two different things, and the switch between them is the page's own
 * logic — nothing on the server decides it, because the choice has to change the FIELDS
 * before anything is posted.
 *
 * Recovering a PASSWORD needs both the username and the email (Fortify matches the account
 * on the username and sends the reset link to the address on file). Recovering a USERNAME
 * needs only the email — asking for the username you have come here to be reminded of is
 * the bug this toggle exists to avoid, and it is a bug nothing else would report: the
 * field would just sit there, required, unanswerable.
 *
 * The switch is driven by the RADIO GROUP'S CHANGE EVENT rather than a v-model, so the two
 * have to stay wired: the group reflects the `checked` flags it is handed and reports
 * changes, and this page keeps the state. That makes "the field appears and disappears with
 * the choice" the assertion, since neither half proves it alone.
 *
 * The resend-verification link is gated on a backend feature flag. It must not render when
 * verification is switched off, because the route behind it does not exist then — a dead
 * link on the page a locked-out reader lands on.
 *
 * That POST /forgot answers identically whether or not an account matches — the property
 * making this form useless for enumerating registered addresses — is a server guarantee and
 * belongs to its feature test.
 */

/** Mount the page with the given backend feature flags. */
const page = (features: Record<string, boolean> = { emailVerification: true }) => {
    setPage({ props: { features } });

    return mountApp(ForgotPage);
};

/** Pick a recovery type the way the radio group reports it. */
const chooseType = async (wrapper: ReturnType<typeof page>, value: string): Promise<void> => {
    await wrapper.find(`#type_${value}`).setValue();
};

describe("ForgotPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("offers both kinds of recovery, starting on the password one", () => {
        const wrapper = page();
        const options = wrapper.findAll("input[name='type']");

        expect(options.map(option => (option.element as HTMLInputElement).value)).toStrictEqual(["password", "name"]);
        expect((options[0].element as HTMLInputElement).checked).toBe(true);
    });

    it("asks for the username as well when a password is what was forgotten", () => {
        const wrapper = page();

        expect(wrapper.find("#name").exists()).toBe(true);
        expect(wrapper.find("#email").exists()).toBe(true);
    });

    it("stops asking for the username when THAT is what was forgotten", async () => {
        // The whole point of the toggle: a reader here cannot supply the thing being recovered.
        const wrapper = page();

        await chooseType(wrapper, "name");

        expect(wrapper.find("#name").exists()).toBe(false);
        expect(wrapper.find("#email").exists()).toBe(true);
    });

    it("brings the username field back when the choice is changed again", async () => {
        const wrapper = page();

        await chooseType(wrapper, "name");
        await chooseType(wrapper, "password");

        expect(wrapper.find("#name").exists()).toBe(true);
    });

    it("posts to the one endpoint that serves both kinds", () => {
        const form = page().findComponent({ name: "InertiaForm" });

        expect(form.props("action")).toBe("/forgot");
        expect(form.props("method")).toBe("post");
    });

    it("offers the resend-verification link only where that feature is switched on", () => {
        // The route does not exist when it is off, so the link would be dead.
        expect(page().find("a[href='/resend-verification']").exists()).toBe(true);
        expect(page({ emailVerification: false }).find("a[href='/resend-verification']").exists()).toBe(false);
    });

    it("heads the page and declares its one crumb", () => {
        expect(page().text()).toContain(translate("auth.forgot.title"));
        expect(getLayoutProps().breadcrumbs).toStrictEqual([{ labelKey: "auth.forgot.pageTitle", icon: "support" }]);
    });
});
