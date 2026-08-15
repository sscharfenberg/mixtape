import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import FormRow from "./FormRow.vue";

/*
 * Fourteen files wrap a field in a FormRow, and every one of them relies on it for the three
 * things a form gets wrong silently: the label pointing at the right input, the error appearing
 * when (and only when) the field is invalid, and the required marker being present.
 *
 * The subtle one is the ANCHOR NAME. Each row anchors its "valid" tick to its own input through a
 * generated CSS anchor name; if two rows shared one, every tick in the form would point at the
 * first field. The name also has to be a valid CSS identifier, which is why the id is stripped —
 * Vue's `useId()` produces colons under SSR, and `anchor-name: --frf-v:0` is a parse error that
 * takes the whole declaration with it.
 *
 * Mounted bare: no i18n, no Inertia. What it needs is a slot.
 */

/** Mount a row with a field in it, as every caller does. */
const row = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
    mountApp(FormRow, {
        props,
        slots: { default: '<input id="name" type="text" />', ...slots }
    });

describe("FormRow", () => {
    it("ties its label to the field it was given", () => {
        // A `for` that misses is invisible on screen and breaks clicking the label, which is how
        // a lot of people focus an input.
        const wrapper = row({ label: "Benutzername", forId: "name" });

        expect(wrapper.find("label").attributes("for")).toBe("name");
        expect(wrapper.find("label").text()).toContain("Benutzername");
    });

    it("still reserves the label row when there is no label", () => {
        // The grid keeps its columns either way; without this the field jumps left in a form
        // where one row happens to be unlabelled.
        const wrapper = row();

        expect(wrapper.find("label").exists()).toBe(false);
        expect(wrapper.find(".label").exists()).toBe(true);
    });

    it("marks a required field, labelled or not", () => {
        expect(row({ label: "Passwort", required: true }).find(".form-row__icon").exists()).toBe(true);
        expect(row({ required: true }).find(".form-row__icon").exists()).toBe(true);
    });

    it("shows an error only when the field is actually invalid", () => {
        // Both halves matter: `error` is often bound to a message that exists before submission,
        // and rendering it eagerly would accuse the reader of a mistake they have not made yet.
        const clean = row({ error: "Pflichtfeld" });
        const invalid = row({ error: "Pflichtfeld", invalid: true });

        expect(clean.find(".form-row__error").exists()).toBe(false);
        expect(invalid.find(".form-row__error").text()).toContain("Pflichtfeld");
    });

    it("says nothing when marked invalid with no message", () => {
        // An empty error box still draws its icon and its gap — a row that shifts for nothing.
        expect(row({ invalid: true }).find(".form-row__error").exists()).toBe(false);
    });

    it("shows the spinner while validating, and the tick only once it is done", () => {
        // Precognition validates as you type, so both states are real and they must not overlap:
        // a tick beside a spinner claims the answer is in while the request is still out.
        const validating = row({ validating: true, validated: true });
        const settled = row({ validated: true });

        expect(validating.find(".form-row--validating").exists()).toBe(true);
        expect(validating.find(".form-row--valid").exists()).toBe(false);
        expect(settled.find(".form-row--valid").exists()).toBe(true);
    });

    it("gives every row in one form its own anchor name", () => {
        /*
         * Two rows sharing a name would land every tick on the first field. Asserted inside ONE
         * mounted app on purpose: `useId()` counts per app instance, so two separate `mount()`
         * calls both produce `v-0` and would pass a comparison that proves nothing. A form is one
         * app, so that is the scope the invariant actually has to hold in.
         */
        const form = mountApp(
            {
                components: { FormRow },
                template: '<div><FormRow validated><input /></FormRow><FormRow validated><input /></FormRow></div>'
            },
            { global: { components: { FormRow } } }
        );

        const [first, second] = form.findAll(".form-row__field").map(node => node.attributes("style"));

        // Trailing semicolon included: that is how Vue serialises a bound `style`. The name must
        // be a CSS identifier — `useId()` yields colons under SSR, and `--frf-v:0` is a parse
        // error that takes the whole declaration with it.
        expect(first).toMatch(/^anchor-name: --frf-[a-z0-9_-]+;?$/iu);
        expect(second).not.toBe(first);
    });

    it("prefers a static addon icon over the addon slot", () => {
        // Both at once is a caller mistake; rendering both would put two glyphs in one gutter.
        const wrapper = row({ addonIcon: "account" }, { addon: '<button type="button">x</button>' });

        expect(wrapper.find(".form-row__addon").exists()).toBe(true);
        expect(wrapper.find(".form-row__addon").attributes("aria-hidden")).toBe("true");
        expect(wrapper.find(".form-row__addon + div button").exists()).toBe(false);
    });

    it("renders the optional hint and trailing button only when passed", () => {
        const bare = row();
        const dressed = row({}, { text: "Mindestens 8 Zeichen", button: "<button>Zeigen</button>" });

        expect(bare.find(".form-row__text").exists()).toBe(false);
        expect(bare.find(".form-row__button").exists()).toBe(false);
        expect(dressed.find(".form-row__text").text()).toContain("8 Zeichen");
        expect(dressed.find(".form-row__button").text()).toContain("Zeigen");
    });
});
