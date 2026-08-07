import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick, ref } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import TwoFactorRecoveryCodes from "./TwoFactorRecoveryCodes.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The recovery-codes panel. It is ONE form with TWO submit buttons, dispatched by reading
 * `event.submitter.value` — and that is the whole reason this file exists.
 *
 * Nothing about the markup says which handler a press runs. Swap the two `value` attributes,
 * or lose them in a refactor to a component that does not forward `value` to its <button>,
 * and the form still submits, still shows a spinner, still succeeds — it just REGENERATES
 * the codes when the reader asked to see them, invalidating every code they had written
 * down. There is no error, and the screen afterwards looks the same either way.
 *
 * The second decision worth holding is that the password field disappears once the codes are
 * on screen. It is gated on `requiresPasswordConfirmation && !isRecoveryCodesVisible`: the
 * session has just been confirmed, so asking again to regenerate is friction, and a field
 * left rendered would also submit a stale password with the regenerate press.
 *
 * The composable is faked here. Its own lifecycle — the 423-retry, the confirmation guard —
 * is covered by useTwoFactorAuth.test.ts; what is untested until now is which of its methods
 * this component calls, and with what.
 */

const handleShowRecoveryCodes = vi.fn();
const handleRegenerateRecoveryCodes = vi.fn();
const isRecoveryCodesVisible = ref(false);
const recoveryCodesList = ref<string[]>([]);
const requiresPasswordConfirmation = ref(true);
const processing = ref(false);
const validationErrors = ref<Record<string, string>>({});

vi.mock("Composables/useTwoFactorAuth", () => ({
    useTwoFactorAuth: () => ({
        handleShowRecoveryCodes,
        handleRegenerateRecoveryCodes,
        isRecoveryCodesVisible,
        recoveryCodesList,
        requiresPasswordConfirmation,
        processing,
        validationErrors
    })
}));

/** Mount the panel. */
const panel = () => mountApp(TwoFactorRecoveryCodes, { attachTo: document.body });

/**
 * Submit the form as a press of the button carrying `value` would.
 *
 * `trigger("submit")` on the form produces an event with NO submitter, which is exactly the
 * case the component has to survive (and the one a keyboard Enter can produce), so the
 * submitter is attached explicitly where a button press is what is being simulated.
 */
const submitVia = async (wrapper: ReturnType<typeof panel>, value: string | null): Promise<void> => {
    const form = wrapper.find("form").element as HTMLFormElement;
    const event = new window.Event("submit", { cancelable: true, bubbles: true }) as SubmitEvent;
    Object.defineProperty(event, "submitter", {
        value: value === null ? null : form.querySelector(`button[value="${value}"]`)
    });
    form.dispatchEvent(event);
    await nextTick();
};

describe("TwoFactorRecoveryCodes", () => {
    beforeEach(() => {
        resetInertia();
        vi.clearAllMocks();
        isRecoveryCodesVisible.value = false;
        recoveryCodesList.value = [];
        requiresPasswordConfirmation.value = true;
        processing.value = false;
        validationErrors.value = {};
        document.body.innerHTML = "";
    });

    it("reveals the codes when the SHOW button submitted, and does not regenerate them", async () => {
        const wrapper = panel();
        await wrapper.find("#recovery-codes-password").setValue("geheim");

        await submitVia(wrapper, "show");

        expect(handleShowRecoveryCodes).toHaveBeenCalledWith("geheim");
        expect(handleRegenerateRecoveryCodes).not.toHaveBeenCalled();
    });

    it("mints a fresh set only when the REGENERATE button submitted", async () => {
        isRecoveryCodesVisible.value = true;
        const wrapper = panel();

        await submitVia(wrapper, "regenerate");

        expect(handleRegenerateRecoveryCodes).toHaveBeenCalledTimes(1);
        expect(handleShowRecoveryCodes).not.toHaveBeenCalled();
    });

    it("does nothing at all for a submit that names no button", async () => {
        // A submitter-less event is what a stray programmatic submit looks like; guessing a
        // default here is how "show" quietly becomes "regenerate".
        const wrapper = panel();

        await submitVia(wrapper, null);

        expect(handleShowRecoveryCodes).not.toHaveBeenCalled();
        expect(handleRegenerateRecoveryCodes).not.toHaveBeenCalled();
    });

    it("shows one button before the reveal and the other after, never both", () => {
        const before = panel();
        expect(before.find("button[value='show']").exists()).toBe(true);
        expect(before.find("button[value='regenerate']").exists()).toBe(false);

        isRecoveryCodesVisible.value = true;
        const after = panel();
        expect(after.find("button[value='show']").exists()).toBe(false);
        expect(after.find("button[value='regenerate']").exists()).toBe(true);
    });

    it("lists the revealed codes one per line, in a field that cannot be edited", () => {
        isRecoveryCodesVisible.value = true;
        recoveryCodesList.value = ["aaaa-bbbb", "cccc-dddd", "eeee-ffff"];

        const textarea = panel().find("textarea");

        expect((textarea.element as HTMLTextAreaElement).value).toBe("aaaa-bbbb\ncccc-dddd\neeee-ffff");
        expect(textarea.attributes("readonly")).toBeDefined();
        expect(textarea.attributes("aria-readonly")).toBe("true");
    });

    it("stops asking for the password once the codes are on screen", () => {
        expect(panel().find("#recovery-codes-password").exists()).toBe(true);

        isRecoveryCodesVisible.value = true;
        expect(panel().find("#recovery-codes-password").exists()).toBe(false);
    });

    it("asks for no password at all when the session was confirmed recently", () => {
        requiresPasswordConfirmation.value = false;

        expect(panel().find("#recovery-codes-password").exists()).toBe(false);
    });

    it("adds and drops legend notes with the state they describe", () => {
        /*
         * The intro is permanent; the required-fields hint belongs to the password field and
         * must go with it; the usage note ("each code works once") only makes sense once
         * there are codes on screen. The hint is matched on its first clause because the key
         * interpolates an icon into the middle of the sentence.
         */
        const hint = translate("common.requiredFieldsHint").split("{icon}")[0];

        const asking = panel();
        expect(asking.text()).toContain(translate("dashboard.twoFactor.recoveryCodes.intro"));
        expect(asking.text()).toContain(hint);
        expect(asking.text()).not.toContain(translate("dashboard.twoFactor.recoveryCodes.usage"));

        isRecoveryCodesVisible.value = true;
        const revealed = panel();
        expect(revealed.text()).toContain(translate("dashboard.twoFactor.recoveryCodes.usage"));
        expect(revealed.text()).not.toContain(hint);
    });

    it("reveals the password as text on request, and says which state the toggle is in", async () => {
        const wrapper = panel();
        const toggle = wrapper.find(".form-row button[type='button']");

        expect(wrapper.find("#recovery-codes-password").attributes("type")).toBe("password");
        expect(toggle.attributes("aria-label")).toBe(translate("common.showPassword"));

        await toggle.trigger("click");

        expect(wrapper.find("#recovery-codes-password").attributes("type")).toBe("text");
        expect(toggle.attributes("aria-label")).toBe(translate("common.hidePassword"));
    });

    it("locks the button while a request is in flight, so the codes cannot be minted twice", () => {
        processing.value = true;

        expect(panel().find("button[value='show']").attributes("disabled")).toBeDefined();
    });
});
