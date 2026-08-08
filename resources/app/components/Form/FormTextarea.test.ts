import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import FormTextarea from "./FormTextarea.vue";

/*
 * The same tripwire FormInput.test.ts is, for the same reason. FormTextarea declares
 * exactly one thing — the model — and leans on Vue's FALLTHROUGH ATTRIBUTES for the
 * rest, which is a deliberate design with a caveat that fails SILENTLY: fallthrough is
 * not type-checked, and it only auto-applies because the root is a single element.
 *
 * `rows` gets its own case because it is this component's substitute for a CSS height.
 * Lose it to a multi-root refactor and every textarea in the app collapses to the UA
 * default of two lines — a form that still works and no longer invites a paragraph.
 */

describe("FormTextarea", () => {
    it("binds v-model to the native textarea", async () => {
        const wrapper = mountApp(FormTextarea, { props: { modelValue: "" } });

        await wrapper.find("textarea").setValue("Quiet things.");

        expect(wrapper.emitted("update:modelValue")).toStrictEqual([["Quiet things."]]);
    });

    it("renders the current model value", () => {
        const wrapper = mountApp(FormTextarea, { props: { modelValue: "Quiet things." } });

        expect(wrapper.find("textarea").element.value).toBe("Quiet things.");
    });

    it("keeps its own class when a parent adds one", () => {
        // class MERGES rather than replacing — the form-row context rules key off
        // `.form-textarea` for the focus glow and the addon seam.
        const wrapper = mountApp(FormTextarea, { attrs: { class: "custom" } });

        expect(wrapper.classes()).toContain("form-textarea");
        expect(wrapper.classes()).toContain("custom");
    });

    it("passes rows through, which is how a caller asks for a paragraph's worth of room", () => {
        const wrapper = mountApp(FormTextarea, { attrs: { rows: "4" } });

        expect(wrapper.find("textarea").attributes("rows")).toBe("4");
    });

    it.each(["id", "name", "maxlength", "placeholder", "aria-describedby"])(
        "passes %s straight through to the textarea",
        attribute => {
            const wrapper = mountApp(FormTextarea, { attrs: { [attribute]: "wert" } });

            expect(wrapper.find("textarea").attributes(attribute)).toBe("wert");
        }
    );

    it("passes listeners through, so @change can be attached at the call site", async () => {
        let changed = false;
        const wrapper = mountApp(FormTextarea, {
            attrs: {
                onChange: () => {
                    changed = true;
                }
            }
        });

        await wrapper.find("textarea").trigger("change");

        expect(changed).toBe(true);
    });

    it("has a single root element, which is what makes all of the above work", () => {
        const wrapper = mountApp(FormTextarea);

        expect(wrapper.element.tagName).toBe("TEXTAREA");
    });
});
