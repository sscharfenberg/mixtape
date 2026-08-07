import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import TwoFactor from "./TwoFactor.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The dashboard's 2FA section, which is a switch between two forms plus a badge — and one
 * piece of placement that is not obvious from reading the template.
 *
 * THE SETUP MODAL IS MOUNTED HERE, not inside TwoFactorDisabled where it is opened from. It
 * has to be: the instant Fortify flips `twoFactorEnabled` to true, the body swaps Disabled →
 * Enabled, and a modal owned by the outgoing component would be torn out of the DOM
 * mid-enrollment — before the reader has typed the confirmation code the modal is asking
 * for. Nesting it one level down is the natural place to put it and it breaks exactly the
 * flow it belongs to. This file pins it: the modal survives the swap.
 *
 * Closing it also has to CLEAR THE SETUP DATA. The QR code and manual key are module
 * singletons, so a modal reopened without clearing shows the previous enrollment's secret —
 * which the authenticator app would happily accept and the server would then reject.
 *
 * The badge is the section's only status output, and it inverts: warning when 2FA is OFF.
 * Reading it the intuitive way round ("success = the thing is on… so warning = off?") is the
 * kind of thing that reads fine in a diff.
 *
 * The real composable is used here, since these flags come from the page props it computes
 * over — which is the wiring under test.
 */

/** Mount the section with 2FA in the given state. */
const section = (props: Record<string, unknown> = {}) => {
    setPage({ props: { twoFactorEnabled: false, requiresConfirmation: true, requiresPasswordConfirmation: false, ...props } });

    return mountApp(TwoFactor, { attachTo: document.body });
};

describe("TwoFactor", () => {
    beforeEach(() => {
        resetInertia();
        useTwoFactorAuth().clearTwoFactorAuthData();
        /*
         * TwoFactorModal fetches the QR code and the setup key on mount. Left alone that is a
         * real request to a dead port, which surfaces as an ECONNREFUSED *after* the suite
         * reports green — a passing run with a stack trace under it. Answering it here keeps
         * the failure surface honest; what the modal does with the payload is its own file's.
         */
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({}) }));
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("offers the enable form while two-factor auth is off", () => {
        const wrapper = section();

        expect(wrapper.findComponent({ name: "TwoFactorDisabled" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "TwoFactorEnabled" }).exists()).toBe(false);
    });

    it("swaps to the disable and recovery-codes forms once it is on", () => {
        const wrapper = section({ twoFactorEnabled: true });

        expect(wrapper.findComponent({ name: "TwoFactorEnabled" }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: "TwoFactorDisabled" }).exists()).toBe(false);
    });

    it("warns while the account is unprotected and confirms once it is not", () => {
        // The badge inverts: warning is the OFF state, which is the point of showing it.
        const off = section();
        expect(off.find(".badge").text()).toContain(translate("dashboard.twoFactor.badge.disabled"));
        expect(off.findComponent({ name: "Badge" }).props("type")).toBe("warning");

        const on = section({ twoFactorEnabled: true });
        expect(on.find(".badge").text()).toContain(translate("dashboard.twoFactor.badge.enabled"));
        expect(on.findComponent({ name: "Badge" }).props("type")).toBe("success");
    });

    it("keeps the setup modal alive across the disabled → enabled swap it triggers", async () => {
        /*
         * The reason the modal is mounted here rather than in TwoFactorDisabled. Enrollment
         * flips the flag straight away; if the modal belonged to the outgoing form it would
         * disappear before the reader could type the confirmation code.
         */
        const wrapper = section();
        useTwoFactorAuth().showSetupModal.value = true;
        await nextTick();

        expect(wrapper.findComponent({ name: "TwoFactorModal" }).exists()).toBe(true);

        setPage({ props: { twoFactorEnabled: true } });
        await nextTick();

        expect(wrapper.findComponent({ name: "TwoFactorDisabled" }).exists()).toBe(false);
        expect(wrapper.findComponent({ name: "TwoFactorModal" }).exists()).toBe(true);
    });

    it("shows no modal until enrollment opens one", () => {
        expect(section().findComponent({ name: "TwoFactorModal" }).exists()).toBe(false);
    });

    it("throws away the enrollment secret on close, so reopening cannot show a stale one", async () => {
        const twoFactor = useTwoFactorAuth();
        const wrapper = section();
        twoFactor.showSetupModal.value = true;
        twoFactor.qrCodeSvg.value = "<svg />";
        twoFactor.manualSetupKey.value = "ABCD-EFGH";
        await nextTick();

        wrapper.findComponent({ name: "TwoFactorModal" }).vm.$emit("close");
        await nextTick();

        expect(twoFactor.showSetupModal.value).toBe(false);
        expect(twoFactor.qrCodeSvg.value).toBeNull();
        expect(twoFactor.manualSetupKey.value).toBeNull();
        expect(wrapper.findComponent({ name: "TwoFactorModal" }).exists()).toBe(false);
    });

    it("anchors the section so the dashboard's jump-nav can reach it", () => {
        expect(section().find("#twoFactorSection").exists()).toBe(true);
    });
});
