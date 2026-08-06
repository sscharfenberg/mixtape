import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import ThemeSwitch from "./ThemeSwitch.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Written when the switch adopted the shared OptionBubbles control, because until then it
 * had no coverage at all and the migration replaced its entire template.
 *
 * What matters here is only what stayed behind: the <meta name="color-scheme"> tag, which
 * is what CSS `light-dark()` and the `theme-dark` mixin actually key off, and the
 * localStorage that outlives the tab. Whether the pill lands in the right place is
 * OptionBubbles' own spec, and where it lands in pixels is Playwright's.
 *
 * The component reads the meta tag at setup and THROWS without one, so every test installs
 * it first — which is also a fair reproduction of the app, where it is in the Blade shell.
 */

/** The tag the component drives, freshly installed with a starting value. */
const installMeta = (content = "light dark"): HTMLMetaElement => {
    document.head.querySelector("meta[name='color-scheme']")?.remove();
    const meta = document.createElement("meta");
    meta.setAttribute("name", "color-scheme");
    meta.setAttribute("content", content);
    document.head.append(meta);

    return meta;
};

/** What the meta tag currently says — the live colour scheme. */
const scheme = () => document.head.querySelector("meta[name='color-scheme']")?.getAttribute("content");

describe("ThemeSwitch", () => {
    beforeEach(() => {
        resetInertia();
        window.localStorage.clear();
        installMeta();
    });

    it("offers dark, light and follow-the-OS, in that order", () => {
        const wrapper = mountApp(ThemeSwitch);

        expect(wrapper.findAll("input").map(input => (input.element as HTMLInputElement).value)).toStrictEqual([
            "dark",
            "light",
            "light dark"
        ]);
    });

    it("marks the scheme the meta tag is already set to", () => {
        installMeta("dark");

        const wrapper = mountApp(ThemeSwitch);

        expect((wrapper.findAll("input")[0].element as HTMLInputElement).checked).toBe(true);
    });

    it("prefers the stored choice over the tag, since that is the one the reader made", () => {
        window.localStorage.setItem("theme", "light");
        installMeta("dark");

        const wrapper = mountApp(ThemeSwitch);

        expect((wrapper.findAll("input")[1].element as HTMLInputElement).checked).toBe(true);
    });

    it("re-applies the stored choice on mount, in case the server-rendered default differs", () => {
        // The flash this exists to prevent: the shell ships one scheme, the reader chose
        // another last week, and without this the page renders in the wrong one until they
        // touch the control.
        window.localStorage.setItem("theme", "dark");
        installMeta("light");

        mountApp(ThemeSwitch);

        expect(scheme()).toBe("dark");
    });

    it("switches the scheme and remembers it", async () => {
        const wrapper = mountApp(ThemeSwitch);

        await wrapper.findAll("input")[0].trigger("change");

        expect(scheme()).toBe("dark");
        expect(window.localStorage.getItem("theme")).toBe("dark");
    });

    it("moves the selection with the scheme, not just the tag", async () => {
        /*
         * The regression this file exists to prevent, found by the Playwright spec after the
         * migration to OptionBubbles. `theme` used to be a computed whose getter read
         * `localStorage` and an attribute — neither reactive — so it never re-evaluated. The
         * old markup hid that: its pill was drawn from `:has(input:checked)`, the browser's
         * own radio state. Draw the selection from component state instead and the staleness
         * is immediate — the scheme changes and the control still shows the previous one.
         */
        const wrapper = mountApp(ThemeSwitch);

        await wrapper.findAll("input")[0].trigger("change");

        expect((wrapper.findAll("input")[0].element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.findAll("input")[2].element as HTMLInputElement).checked).toBe(false);
        // The pill reads the same state, through the index the group publishes to CSS.
        expect(wrapper.find(".option-bubbles").attributes("style")).toContain("--selected: 0");
    });

    it("explains the system option to assistive tech, not just to a mouse", async () => {
        /*
         * "System" on its own says nothing about what it does — which is why it has a hint at
         * all — and a hint only shown on hover reaches nobody using a keyboard or a screen
         * reader. This is the one option where the gap actually mattered, so it is asserted
         * against the real catalogue rather than a stand-in string.
         */
        const wrapper = mountApp(ThemeSwitch);
        const system = wrapper.findAll("input")[2];

        expect(system.attributes("aria-label")).toBe("System");

        const description = wrapper.find(`#${system.attributes("aria-describedby")}`);
        expect(description.classes()).toContain("sr-only");
        expect(description.text()).toContain("Betriebssystem");

        // Chosen, it states the mode rather than offering the switch — and German is default.
        await system.trigger("change");

        expect(wrapper.find(`#${system.attributes("aria-describedby")}`).text()).toMatch(/^System-Modus:/u);
    });

    it("stores the OS option as the CSS value it is, spaces and all", async () => {
        // `"light dark"` is a real `color-scheme` value, not a label — the id derived from
        // it is slugged, but what gets stored and written to the tag must not be.
        const wrapper = mountApp(ThemeSwitch);

        await wrapper.findAll("input")[2].trigger("change");

        expect(scheme()).toBe("light dark");
        expect(window.localStorage.getItem("theme")).toBe("light dark");
        expect(wrapper.findAll("input")[2].attributes("id")).toBe("theme-light-dark");
    });
});
