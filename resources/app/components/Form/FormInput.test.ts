import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import FormInput from "./FormInput.vue";

/*
 * FormInput declares exactly one thing — the model — and leans on Vue's FALLTHROUGH
 * ATTRIBUTES for everything else. That is a deliberate design (see the component's
 * banner) with a documented caveat: fallthrough is NOT type-checked, so a misspelt
 * attribute silently does nothing, and it only auto-applies because the root is a single
 * element. A multi-root refactor would break every consumer at once, silently.
 *
 * These tests are the tripwire for that: they assert the properties the banner promises,
 * so the day someone adds a second root node or flips inheritAttrs, it fails here rather
 * than in whichever form quietly stopped passing its `type` or `autocomplete` through.
 */

describe("FormInput", () => {
    it("binds v-model to the native input", async () => {
        const wrapper = mountApp(FormInput, { props: { modelValue: "" } });

        await wrapper.find("input").setValue("Ashaltiriak");

        expect(wrapper.emitted("update:modelValue")).toStrictEqual([["Ashaltiriak"]]);
    });

    it("renders the current model value", () => {
        const wrapper = mountApp(FormInput, { props: { modelValue: "Ashaltiriak" } });

        expect(wrapper.find("input").element.value).toBe("Ashaltiriak");
    });

    it("keeps its own class when a parent adds one", () => {
        // class MERGES rather than replacing — the form-row context rules depend on it.
        const wrapper = mountApp(FormInput, { attrs: { class: "custom" } });

        expect(wrapper.classes()).toContain("form-input");
        expect(wrapper.classes()).toContain("custom");
    });

    it("lets the parent own the input type, for a password toggle", () => {
        const wrapper = mountApp(FormInput, { attrs: { type: "password" } });

        expect(wrapper.find("input").attributes("type")).toBe("password");
    });

    it.each(["id", "name", "autocomplete", "placeholder", "aria-describedby"])(
        "passes %s straight through to the input",
        attribute => {
            const wrapper = mountApp(FormInput, { attrs: { [attribute]: "wert" } });

            expect(wrapper.find("input").attributes(attribute)).toBe("wert");
        }
    );

    it("passes listeners through, so @blur can be attached at the call site", async () => {
        let blurred = false;
        const wrapper = mountApp(FormInput, {
            attrs: {
                onBlur: () => {
                    blurred = true;
                }
            }
        });

        await wrapper.find("input").trigger("blur");

        expect(blurred).toBe(true);
    });

    it("has a single root element, which is what makes all of the above work", () => {
        // inheritAttrs only auto-applies to a lone root; a multi-root refactor would
        // silently strip every attribute above.
        const wrapper = mountApp(FormInput);

        expect(wrapper.element.tagName).toBe("INPUT");
    });
});
