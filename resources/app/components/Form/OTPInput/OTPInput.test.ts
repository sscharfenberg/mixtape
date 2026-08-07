import { describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { mountApp } from "Testing/mount";
import OTPInput from "./OTPInput.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The 2FA code field, on the login challenge and on enrollment. It is a thin wrapper around
 * `vue-input-otp`, so what is worth testing is not the library but the WIRING — the four
 * places our code touches it, each of which fails quietly:
 *
 *   - `inheritAttrs: false` plus an explicit `v-bind="attrs"`. Get that pair wrong and the
 *     forwarded attributes land on the wrapper div instead of the real input: `id`,
 *     `autocomplete` and `name` all detach, so the <label for> stops pointing at anything, a
 *     password manager stops offering the code, and the field submits under no name at all.
 *     Every one of those is invisible on screen.
 *   - `autocomplete="one-time-code"`, which is what makes a phone offer the SMS/authenticator
 *     code above the keyboard. A default of "off" would be a reasonable-looking change that
 *     costs the feature the field exists for.
 *   - one painted box per `maxlength`, so the boxes and the value cannot disagree.
 *   - `complete`, forwarded verbatim. TwoFactorModal SUBMITS on it, so a swallowed event
 *     means the reader types a full code and nothing happens.
 *
 * Focus behaviour — the `autofocus` fallback chain — needs a real focus model and belongs to
 * Playwright; happy-dom will report whatever it is told.
 */

/** Mount the field. */
const otp = (props: Record<string, unknown> = {}, attrs: Record<string, unknown> = {}) =>
    mountApp(OTPInput, { props: { id: "code", name: "code", ...props }, attrs });

/** The library's single hidden input, where the real value lives. */
const hiddenInput = (wrapper: ReturnType<typeof otp>) => wrapper.find("input[data-input-otp]");

describe("OTPInput", () => {
    it("keeps the value in one real input, so paste and autofill still work", () => {
        // The visible boxes are painted divs; a per-box input would break both.
        const wrapper = otp();

        expect(hiddenInput(wrapper).exists()).toBe(true);
        expect(wrapper.findAll("input")).toHaveLength(1);
    });

    it("puts the forwarded id and name on that input and not on the wrapper", () => {
        const input = hiddenInput(otp());

        expect(input.attributes("id")).toBe("code");
        expect(input.attributes("name")).toBe("code");
    });

    it("asks the platform for the one-time code, which is why the field exists", () => {
        expect(hiddenInput(otp()).attributes("autocomplete")).toBe("one-time-code");
    });

    it("defaults to a numeric keypad, and takes text when a caller needs it", () => {
        expect(hiddenInput(otp()).attributes("inputmode")).toBe("numeric");
        expect(hiddenInput(otp({ inputmode: "text" })).attributes("inputmode")).toBe("text");
    });

    it("paints one box per expected character, so the boxes cannot disagree with the value", () => {
        expect(otp().findAll(".otp__char")).toHaveLength(6);
        expect(otp({ maxlength: 8 }).findAll(".otp__char")).toHaveLength(8);
    });

    it("shows a bound code in the boxes, one character each", async () => {
        const wrapper = otp({ modelValue: "1234" });
        await nextTick();

        expect(wrapper.findAll(".otp__char").map(box => box.text())).toStrictEqual(["1", "2", "3", "4", "", ""]);
    });

    it("reports what was typed, and says the code is complete once it is full", async () => {
        // TwoFactorModal submits on `complete`; swallowing it means a full code does nothing.
        const wrapper = otp();

        await hiddenInput(wrapper).setValue("123456");

        // The last update carries the whole code. (`.at()` is out — the project is lib: ES2020.)
        const updates = wrapper.emitted("update:modelValue")!;
        expect(updates[updates.length - 1]).toStrictEqual(["123456"]);
        expect(wrapper.emitted("complete")).toStrictEqual([["123456"]]);
    });

    it("stays quiet until every box is filled", async () => {
        const wrapper = otp();

        await hiddenInput(wrapper).setValue("12345");

        expect(wrapper.emitted("complete")).toBeUndefined();
    });
});
