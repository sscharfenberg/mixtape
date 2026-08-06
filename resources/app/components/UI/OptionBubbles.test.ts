import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import OptionBubbles from "./OptionBubbles.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The generic half of the control: that it reports a choice, and that the pill lands on
 * the chosen option however many options there are.
 *
 * The pill's POSITION is asserted through the two custom properties that place it
 * (`--selected`, `--count`) rather than through geometry, because geometry here is one
 * `calc()` in a stylesheet happy-dom does not resolve — a test that read `left` would be
 * asserting the mock. Where the pill actually ends up is a Playwright question.
 *
 * Not tested here: arrow-key navigation between the options. That is the browser's own
 * radiogroup behaviour, which is precisely WHY the control is built from radios — testing
 * it would be testing happy-dom's implementation of it.
 */

const OPTIONS = [
    { value: "off", icon: "shuffle_off", label: "In order" },
    { value: "on", icon: "shuffle", label: "Shuffle" }
];

/** Mount with a given selection, in the shape a caller uses it (`v-model`, so a prop plus an event). */
const bubbles = (modelValue = "off", options = OPTIONS) =>
    mountApp(OptionBubbles, {
        props: { modelValue, options, name: "mode", label: "Play order" }
    });

describe("OptionBubbles", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("draws one option per glyph, in the order given", () => {
        const wrapper = bubbles();

        expect(wrapper.findAll("use").map(node => node.attributes("href"))).toStrictEqual([
            "#shuffle_off",
            "#shuffle"
        ]);
    });

    it("checks the option matching the value, and only that one", () => {
        const wrapper = bubbles("on");

        expect(wrapper.findAll("input").map(input => (input.element as HTMLInputElement).checked)).toStrictEqual([
            false,
            true
        ]);
    });

    it("puts the pill on the chosen option", () => {
        const style = (value: string) => bubbles(value).find(".option-bubbles").attributes("style") ?? "";

        expect(style("off")).toContain("--selected: 0");
        expect(style("on")).toContain("--selected: 1");
    });

    it("tells the pill how many options to divide the row into", () => {
        // The pill is one element behind the row, so its width is `100% / count` — the
        // count has to reach CSS, and three options is the case a two-option assertion
        // would let through.
        const three = bubbles("off", [...OPTIONS, { value: "one", icon: "repeat", label: "Repeat one" }]);

        expect(three.find(".option-bubbles").attributes("style")).toContain("--count: 3");
    });

    it("parks the pill on the first option rather than off the edge for an unknown value", () => {
        // A missing match used to mean `--selected: -1`, which draws the pill outside the
        // control and reads as a rendering fault rather than a state.
        expect(bubbles("nonsense").find(".option-bubbles").attributes("style")).toContain("--selected: 0");
    });

    it("reports the value chosen, without changing anything itself", async () => {
        // Deliberately NOT self-updating: the caller owns the state (here the queue's own
        // flags), so the control emits and redraws from the prop coming back.
        const wrapper = bubbles("off");

        await wrapper.findAll("input")[1].trigger("change");

        expect(wrapper.emitted("update:modelValue")).toStrictEqual([["on"]]);
        expect(wrapper.find(".option-bubbles").attributes("style")).toContain("--selected: 0");
    });

    it("names the group and every option in it, since none of them carries text", () => {
        const wrapper = bubbles();

        expect(wrapper.find(".option-bubbles").attributes("role")).toBe("radiogroup");
        expect(wrapper.find(".option-bubbles").attributes("aria-label")).toBe("Play order");
        expect(wrapper.findAll("input").map(input => input.attributes("aria-label"))).toStrictEqual([
            "In order",
            "Shuffle"
        ]);
    });

    it("ties each label to its own input, so a click lands on the right option", () => {
        // The label is the whole visible control (the input is clipped to a pixel), so a
        // mismatched `for` would leave the glyphs looking right and doing nothing.
        const wrapper = bubbles();
        const ids = wrapper.findAll("input").map(input => input.attributes("id"));

        expect(ids).toStrictEqual(["mode-off", "mode-on"]);
        expect(wrapper.findAll("label").map(label => label.attributes("for"))).toStrictEqual(ids);
    });
});
