import { describe, expect, it, vi } from "vitest";
import { iconNames, mountApp, translate } from "Testing/mount";
import RadioButton from "./RadioButton.vue";
import RadioButtonGroup from "./RadioButtonGroup.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The recovery-type switch on ForgotPage and the 2FA challenge switch on LoginPage are both
 * this component, and both are load-bearing: pick the wrong option and the form asks for
 * the wrong fields, or posts a `type` the controller does not handle.
 *
 * Three things it owes them:
 *
 *   - ONE shared `name` across every option. That is the entire mechanism making the
 *     options mutually exclusive; without it a reader can tick both and the last one wins
 *     at submit time, which reads as the form ignoring their choice.
 *   - the native CHANGE EVENT, forwarded intact. The pages read `event.target.value` off it
 *     to decide which fields to show — so an event re-emitted as a bare value, or a custom
 *     payload, breaks the caller without breaking this component.
 *   - `id`/`for` pairing per option, which is what makes the label clickable and gives the
 *     radio its accessible name. The box itself is drawn by CSS on a visually-hidden input,
 *     so a broken pair leaves a control that cannot be operated by clicking its text.
 *
 * The `checked` prop comes from the caller's own list rather than from a v-model, which is
 * this component's quirk: it reflects what it was handed and reports changes, and the page
 * keeps its own state (see ForgotPage). Worth knowing before assuming the group tracks
 * selection itself.
 */

const OPTIONS = [
    { value: "password", label: "Passwort", checked: true, icon: "key" },
    { value: "name", label: "Benutzername", checked: false, icon: "account" }
];

/** Mount the group over the two recovery types. */
const group = (props: Record<string, unknown> = {}) =>
    mountApp(RadioButtonGroup, { props: { name: "type", radioButtons: OPTIONS, ...props } });

describe("RadioButtonGroup", () => {
    it("puts every option in one group, so exactly one can be chosen", () => {
        const inputs = group().findAll("input[type='radio']");

        expect(inputs).toHaveLength(2);
        expect(inputs.every(input => input.attributes("name") === "type")).toBe(true);
        expect(inputs.map(input => (input.element as HTMLInputElement).value)).toStrictEqual(["password", "name"]);
    });

    it("reflects which option the caller says is chosen", () => {
        const checked = group()
            .findAll("input")
            .map(input => (input.element as HTMLInputElement).checked);

        expect(checked).toStrictEqual([true, false]);
    });

    it("forwards the native change event, which is what the page reads its value from", async () => {
        const wrapper = group();

        await wrapper.find("#type_name").setValue();

        const emitted = wrapper.emitted("change");
        expect(emitted).toHaveLength(1);
        const event = emitted![0][0] as Event;
        expect((event.target as HTMLInputElement).value).toBe("name");
    });

    it("pairs each label with its own input, so the text is the click target", () => {
        const wrapper = group();

        expect(wrapper.findAll("label").map(label => label.attributes("for"))).toStrictEqual(["type_password", "type_name"]);
        expect(wrapper.findAll("input").map(input => input.attributes("id"))).toStrictEqual(["type_password", "type_name"]);
    });

    it("lays out as a column unless asked for a row", () => {
        expect(group().find("ul").classes()).toContain("radio-group--column");
        expect(group({ layout: "row" }).find("ul").classes()).toContain("radio-group--row");
    });

    it("names the list, since the styled-away markers cost it its list semantics", () => {
        const list = group().find("ul");

        expect(list.attributes("role")).toBe("list");
        expect(list.attributes("aria-label")).toBe(translate("common.availableOptions"));
    });
});

describe("RadioButton", () => {
    /** Mount a single option. */
    const radio = (props: Record<string, unknown> = {}) =>
        mountApp(RadioButton, { props: { value: "password", name: "type", checked: false, ...props } });

    it("draws its icon beside the label when it has both", () => {
        expect(iconNames(radio({ label: "Passwort", icon: "key" }))).toStrictEqual(["key"]);
    });

    it("renders only the box when there is no label to hang anything on", () => {
        // An icon with no label has nowhere to go — the icon lives INSIDE the label span.
        const wrapper = radio({ icon: "key" });

        expect(wrapper.find(".form-radio__label").exists()).toBe(false);
        expect(iconNames(wrapper)).toStrictEqual([]);
        expect(wrapper.find(".form-radio__button").exists()).toBe(true);
    });
});
