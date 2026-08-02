import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import LoginPage from "./LoginPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * LoginPage keeps the whole login + two-factor challenge on ONE page. The composable's
 * own tests cover the request shapes; what is left to prove here is that the PAGE swaps
 * itself correctly when Fortify says a second factor is needed — the credential fields
 * go away, the code field appears, the button relabels — and that the feature-gated
 * recovery links only render when the backend says the feature is on.
 *
 * Errors come back on the `name` field, because Fortify::username() === 'name'. A test
 * pins that, since a rename on the server would silently strand every error message with
 * nowhere to render.
 */

/** Queue one fetch response for the login endpoint. */
const mockFetch = (status: number, body: unknown = {}) => {
    vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue({
            ok: status >= 200 && status < 300,
            status,
            json: () => Promise.resolve(body)
        })
    );
};

/** Mount the login page with the given shared props. */
const page = (props: Record<string, unknown> = {}, pageProps: Record<string, unknown> = {}) => {
    setPage({
        props: {
            csrfToken: "test-csrf-token",
            features: { resetPasswords: true, emailVerification: true },
            ...pageProps
        }
    });

    return mountApp(LoginPage, { props });
};

/** Fill the credential fields and submit the form. */
const signIn = async (wrapper: ReturnType<typeof page>, name = "Ashaltiriak", password = "passwort") => {
    await wrapper.find("#name").setValue(name);
    await wrapper.find("#password").setValue(password);
    await wrapper.find("form").trigger("submit");
    await flushPromises();
};

describe("LoginPage", () => {
    beforeEach(() => {
        resetInertia();
        useBreadcrumbs().setBreadcrumbs([]);
        vi.unstubAllGlobals();
    });

    it("declares a single breadcrumb, since the recovery pages are siblings not children", () => {
        page();

        // A reset link arrives by mail and is reached without passing through login.
        expect(useBreadcrumbs().crumbs.value).toStrictEqual([{ labelKey: "auth.login.pageTitle", icon: "key" }]);
    });

    it("renders the credential fields", () => {
        const wrapper = page();

        expect(wrapper.find("#name").exists()).toBe(true);
        expect(wrapper.find("#password").exists()).toBe(true);
    });

    it("shows a session status message when one is passed", () => {
        const wrapper = page({ status: "Dein Passwort wurde geändert." });

        expect(wrapper.find("[role='status']").text()).toBe("Dein Passwort wurde geändert.");
    });

    it("renders no status paragraph when there is none", () => {
        expect(page().find("[role='status']").exists()).toBe(false);
    });

    it("submits the credentials the user typed", async () => {
        mockFetch(200, { two_factor: false, redirect: "/dashboard" });
        const wrapper = page();

        await signIn(wrapper);

        expect(vi.mocked(fetch).mock.calls[0][0]).toBe("/login");
        expect(JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string)).toMatchObject({
            name: "Ashaltiriak",
            password: "passwort"
        });
    });

    it("navigates on a successful login", async () => {
        mockFetch(200, { two_factor: false, redirect: "/music" });
        const wrapper = page();

        await signIn(wrapper);

        expect(routerCalls).toStrictEqual([{ method: "visit", url: "/music", options: undefined }]);
    });

    it("shows a failed-credentials error on the name field", async () => {
        // Fortify::username() === 'name', so the error lands there rather than on password.
        mockFetch(422, { errors: { name: ["Diese Zugangsdaten passen nicht zu unseren Daten."] } });
        const wrapper = page();

        await signIn(wrapper, "Ashaltiriak", "falsch");

        expect(wrapper.text()).toContain("Diese Zugangsdaten passen nicht zu unseren Daten.");
        expect(routerCalls).toHaveLength(0);
    });

    describe("the two-factor challenge", () => {
        /** Log in as a user Fortify says needs a second factor. */
        const challenge = async () => {
            mockFetch(200, { two_factor: true });
            const wrapper = page();
            await signIn(wrapper);

            return wrapper;
        };

        it("swaps the credential fields for the code field, without navigating", async () => {
            const wrapper = await challenge();

            expect(wrapper.find("#name").exists()).toBe(false);
            expect(wrapper.find("#password").exists()).toBe(false);
            // The whole point of the JSON login: the challenge stays on this page.
            expect(routerCalls).toHaveLength(0);
        });

        it("explains the extra step in the form legend", async () => {
            const wrapper = await challenge();

            expect(wrapper.text()).toContain(translate("auth.login.twoFactorHint"));
        });

        it("relabels the submit button for the challenge stage", async () => {
            // Asserted on the button itself rather than the page text: "Anmelden" is both
            // the submit label AND the page title, so a whole-page search cannot tell
            // "the button still says log in" from "the heading says Log in".
            const before = page();
            expect(before.find("button[type='submit']").text()).toContain(translate("auth.login.submit"));
            before.unmount();

            const wrapper = await challenge();

            expect(wrapper.find("button[type='submit']").text()).toContain(translate("auth.login.verify"));
        });

        it("offers a choice between an authenticator code and a recovery code", async () => {
            const wrapper = await challenge();

            expect(wrapper.text()).toContain(translate("auth.login.useOtp"));
            expect(wrapper.text()).toContain(translate("auth.login.useRecoveryCode"));
        });

        it("clears any typed code when switching between the two kinds", async () => {
            // Otherwise six digits would be left sitting in the recovery-code field.
            const wrapper = await challenge();
            const recoveryRadio = wrapper.findAll("input[type='radio']")[1];

            await recoveryRadio.setValue(true);
            await recoveryRadio.trigger("change");

            const textInputs = wrapper.findAll("input[type='text']");
            textInputs.forEach(input => expect((input.element as HTMLInputElement).value).toBe(""));
        });
    });

    describe("the feature-gated recovery links", () => {
        it("offers password recovery when the feature is on", () => {
            const wrapper = page({}, { features: { resetPasswords: true, emailVerification: false } });

            expect(wrapper.html()).toContain('href="/forgot"');
        });

        it("hides password recovery when the feature is off", () => {
            const wrapper = page({}, { features: { resetPasswords: false, emailVerification: false } });

            expect(wrapper.html()).not.toContain('href="/forgot"');
        });

        it("offers to resend a verification mail only when that feature is on", () => {
            const on = page({}, { features: { resetPasswords: false, emailVerification: true } });
            expect(on.html()).toContain('href="/resend-verification"');

            on.unmount();
            const off = page({}, { features: { resetPasswords: false, emailVerification: false } });
            expect(off.html()).not.toContain('href="/resend-verification"');
        });

        it("drops the whole help-links row when both features are off", () => {
            const wrapper = page({}, { features: { resetPasswords: false, emailVerification: false } });

            expect(wrapper.html()).not.toContain(translate("auth.login.helpLinksLabel"));
        });

        it("never offers registration, because it is invite-only", () => {
            const wrapper = page();

            expect(wrapper.html()).not.toContain("/register");
        });
    });
});
