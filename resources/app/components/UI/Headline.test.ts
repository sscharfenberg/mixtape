import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Headline from "./Headline.vue";

/*
 * Twenty-five files render a Headline, and the one thing it decides is the HEADING LEVEL —
 * which is a document-outline decision, not a size. That became worth pinning on 2026-08-06,
 * when the detail pages turned out to be shipping a second <h1> each: the audit that followed
 * cleared this component precisely because `size` cannot go below 2, and nothing was holding
 * that. A `size?: 1 | 2 | 3 | 4` added in a hurry would put the bug straight back.
 *
 * Mounted bare rather than through `mountApp`: it needs no i18n, no Inertia and no router, and
 * a test that installs them anyway hides the fact that this component is that simple.
 */

describe("Headline", () => {
    it("renders an h2 by default, because the page's h1 is the wordmark", () => {
        const wrapper = mount(Headline, { slots: { default: "Alben" } });

        expect(wrapper.find("h2").exists()).toBe(true);
        expect(wrapper.find("h2").text()).toContain("Alben");
    });

    it("renders the level it is asked for, and only that one", () => {
        for (const size of [2, 3, 4] as const) {
            const wrapper = mount(Headline, { props: { size }, slots: { default: "Titel" } });

            expect(wrapper.findAll(`h${size}`)).toHaveLength(1);
            // Never two headings: the template is three sibling `v-if`s rather than a dynamic
            // element, so a stray `v-else-if` would be an easy way to render two at once.
            expect(wrapper.findAll("h2, h3, h4")).toHaveLength(1);
        }
    });

    it("holds the whole default slot in ONE element, so a long title cannot be moved off its line", () => {
        /*
         * The structural half of the fix made on 2026-08-11, when a song whose name runs to
         * four slash-separated clauses rendered BELOW its own icon rather than beside it. A
         * wrapping flex row collects items into lines by their max-content size, so an
         * unwrapped title — an anonymous flex item — was pushed onto a line of its own before
         * it could shrink and wrap inside one.
         *
         * Only the structure is assertable here: happy-dom has no layout, so a test that
         * claimed to check where the icon SITS would be asserting nothing. What this holds is
         * the precondition the CSS rests on — that the icon and the title are one flex item —
         * which is exactly what an innocent-looking template edit would undo.
         */
        const wrapper = mount(Headline, { slots: { default: "<svg class=\"icon\" /> A very long title" } });
        const content = wrapper.findAll(".headline__content");

        expect(content).toHaveLength(1);
        expect(content[0].find("svg.icon").exists()).toBe(true);
        expect(content[0].text()).toContain("A very long title");
    });

    it("keeps the #right slot OUTSIDE that element, so it can still wrap below on a phone", () => {
        // The heading's own `flex-wrap` is what lets a badge drop under the title; folding it
        // in with the content would take that away.
        const wrapper = mount(Headline, { slots: { default: "Titel", right: "12" } });

        expect(wrapper.find(".headline__content").text()).toBe("Titel");
        expect(wrapper.find(".right").text()).toBe("12");
        expect(wrapper.find(".headline__content .right").exists()).toBe(false);
    });

    it("takes an anchor id, so a heading can be a scroll target", () => {
        // The discography's `scroll-margin-top` trick relies on landing on a real element id.
        const wrapper = mount(Headline, { props: { anchorId: "discography" } });

        expect(wrapper.find("h2").attributes("id")).toBe("discography");
    });

    it("dresses itself in the glowing border only when asked", () => {
        const plain = mount(Headline, { slots: { default: "x" } });
        const glowing = mount(Headline, { props: { glow: true }, slots: { default: "x" } });

        expect(plain.find("h2").classes()).not.toContain("glowing-border");
        expect(glowing.find("h2").classes()).toContain("glowing-border");
        // Left is the default edge, so the right-hand modifier stays off.
        expect(glowing.find("h2").classes()).not.toContain("glowing-border--right");
    });

    it("adds the right-edge modifier only together with the glow", () => {
        // `align` alone must not add it: the modifier styles a border the plain heading has no
        // business drawing, and `align="right"` without `glow` is a caller mistake, not a look.
        const alignedOnly = mount(Headline, { props: { align: "right" } });
        const both = mount(Headline, { props: { glow: true, align: "right" } });

        expect(alignedOnly.find("h2").classes()).not.toContain("glowing-border--right");
        expect(both.find("h2").classes()).toContain("glowing-border--right");
    });

    it("renders the trailing slot only when something was passed", () => {
        // The counts beside "Alben 6" live here. An empty `.right` would still take its
        // `margin-left: auto` and push the underline about for nothing.
        const without = mount(Headline, { slots: { default: "Alben" } });
        const with_ = mount(Headline, { slots: { default: "Alben", right: "6" } });

        expect(without.find(".right").exists()).toBe(false);
        expect(with_.find(".right").text()).toBe("6");
    });
});
