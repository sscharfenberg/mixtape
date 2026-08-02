import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import Icon from "./Icon.vue";

/*
 * Icon builds a class list and points a <use> at a sprite symbol. Small, but every
 * icon in the app goes through it, and the class list is what the size tokens hang
 * off — a wrong size index silently renders every icon at the wrong scale.
 */

describe("Icon", () => {
    it("references the named sprite symbol", () => {
        const wrapper = mountApp(Icon, { props: { name: "close" } });

        // The template writes `xlink:href`, which serializes as such — but the DOM
        // exposes the namespaced attribute under its LOCAL name, so attributes() keys
        // it as "href". Assert both, so neither the markup nor the lookup can drift.
        expect(wrapper.html()).toContain('xlink:href="#close"');
        expect(wrapper.find("use").attributes("href")).toBe("#close");
    });

    it("maps the numeric size step onto its class, defaulting to medium", () => {
        // The step→class order is what the s.$c-icon tokens key off.
        const steps = ["tiny", "small", "medium", "large", "xlarge", "max"];

        steps.forEach((expected, size) => {
            const wrapper = mountApp(Icon, { props: { name: "close", size } });

            expect(wrapper.classes()).toContain(expected);
        });

        expect(mountApp(Icon, { props: { name: "close" } }).classes()).toContain("medium");
    });

    it("always carries the base class and the icon's own name", () => {
        const wrapper = mountApp(Icon, { props: { name: "music" } });

        expect(wrapper.classes()).toContain("icon");
        expect(wrapper.classes()).toContain("music");
    });

    it("adds the rotate class only when asked", () => {
        expect(mountApp(Icon, { props: { name: "spinner" } }).classes()).not.toContain("rotate");
        expect(mountApp(Icon, { props: { name: "spinner", rotate: true } }).classes()).toContain("rotate");
    });

    it("merges additional classes without duplicating the base class", () => {
        const wrapper = mountApp(Icon, {
            props: { name: "close", additionalClasses: ["icon", "custom"] }
        });

        // "icon" is deduped by the Set in cssClasses — assert it appears exactly once.
        expect(wrapper.classes().filter(cssClass => cssClass === "icon")).toHaveLength(1);
        expect(wrapper.classes()).toContain("custom");
    });
});
