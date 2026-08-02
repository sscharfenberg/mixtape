import { describe, expect, it } from "vitest";
import { iconNames, mountApp, translate } from "Testing/mount";
import PasswordStrength from "./PasswordStrength.vue";

/*
 * The strength meter's one job is to agree with the server. `score >= 3` is the
 * threshold the PasswordEntropy validation rule accepts, so if this component's idea of
 * "strong" ever drifts from that number, the meter shows a green tick on a password the
 * form will then reject — the most annoying possible bug in a registration flow.
 *
 * The cover width is the other half: it HIDES the unearned right-hand portion of the
 * track, so it shrinks as the score climbs and must be gone entirely at 4.
 */

/** Mount the meter at a given score. */
const meter = (score: number) => mountApp(PasswordStrength, { props: { score } });

describe("PasswordStrength", () => {
    it.each([
        [0, "90%"],
        [1, "70%"],
        [2, "50%"],
        [3, "30%"],
        [4, "0%"]
    ])("at score %i covers %s of the track", (score, width) => {
        expect(meter(score).find(".password-strength__cover").attributes("style")).toContain(`width: ${width}`);
    });

    it.each([
        [0, false],
        [1, false],
        [2, false],
        [3, true],
        [4, true]
    ])("treats score %i as strong=%s, matching the PasswordEntropy rule", (score, strong) => {
        const wrapper = meter(score);

        expect(wrapper.classes().includes("password-strength--strong")).toBe(strong);
        // The chip has to agree with the class, or the two halves of the meter disagree.
        expect(iconNames(wrapper)).toStrictEqual([strong ? "check" : "warning"]);
    });

    it("exposes the score to assistive tech as a meter", () => {
        const wrapper = meter(2);
        const bar = wrapper.find("[role='meter']");

        expect(bar.attributes("aria-valuenow")).toBe("2");
        expect(bar.attributes("aria-valuemin")).toBe("0");
        expect(bar.attributes("aria-valuemax")).toBe("4");
        expect(bar.attributes("aria-label")).toBe(translate("common.passwordStrength"));
    });

    it("follows the score as the user types", async () => {
        const wrapper = meter(1);
        expect(wrapper.classes()).not.toContain("password-strength--strong");

        await wrapper.setProps({ score: 4 });

        expect(wrapper.classes()).toContain("password-strength--strong");
        expect(wrapper.find(".password-strength__cover").attributes("style")).toContain("width: 0%");
    });
});
