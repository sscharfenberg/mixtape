import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import Accordion from "./Accordion.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The two things a disclosure stack has to get right, neither of which is visible from the
 * page that uses it: the ARIA wiring between a header and its panel, and what `closeOther`
 * actually does to the open set.
 *
 * The panel is `v-if`'d rather than hidden, so "is it open" is a question about the DOM
 * rather than about a class — which is what these assert.
 */

const sections = [
    { id: "lovecraft", label: "H.P. Lovecraft", meta: "6 Bücher" },
    { id: "king", label: "Stephen King", meta: "1 Buch" },
    { id: "eschbach", label: "Andreas Eschbach", icon: "author" }
];

/** Mount the stack with one identifiable paragraph per panel. */
const stack = (props: Record<string, unknown> = {}) =>
    mountApp(Accordion, {
        props: { name: "authors", sections, ...props },
        slots: {
            lovecraft: "<p>lovecraft-shelf</p>",
            king: "<p>king-shelf</p>",
            eschbach: "<p>eschbach-shelf</p>"
        }
    });

/** The ids of the sections whose panel is actually in the DOM. */
const openPanels = (wrapper: ReturnType<typeof stack>): string[] =>
    wrapper.findAll("[role='region']").map(node => (node.attributes("id") ?? "").replace("authors-accordion-panel-", ""));

describe("Accordion", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("opens closed, so the first thing seen is the whole list of names", () => {
        const wrapper = stack();

        expect(wrapper.findAll(".accordion__trigger")).toHaveLength(3);
        expect(openPanels(wrapper)).toStrictEqual([]);
        expect(wrapper.text()).not.toContain("lovecraft-shelf");
    });

    it("renders each section's own slot when it is opened", async () => {
        const wrapper = stack();

        await wrapper.findAll(".accordion__trigger")[1].trigger("click");

        expect(openPanels(wrapper)).toStrictEqual(["king"]);
        expect(wrapper.text()).toContain("king-shelf");
        expect(wrapper.text()).not.toContain("lovecraft-shelf");
    });

    it("closes the previous section when closeOther is on", async () => {
        const wrapper = stack({ closeOther: true });

        await wrapper.findAll(".accordion__trigger")[0].trigger("click");
        await wrapper.findAll(".accordion__trigger")[1].trigger("click");

        expect(openPanels(wrapper)).toStrictEqual(["king"]);
    });

    it("keeps both open when closeOther is off", async () => {
        const wrapper = stack({ closeOther: false });

        await wrapper.findAll(".accordion__trigger")[0].trigger("click");
        await wrapper.findAll(".accordion__trigger")[1].trigger("click");

        expect(openPanels(wrapper)).toStrictEqual(["lovecraft", "king"]);
    });

    it("lets the last open section be closed again", async () => {
        // An accordion that insists on keeping one section open is a tablist in disguise.
        const wrapper = stack();

        await wrapper.findAll(".accordion__trigger")[0].trigger("click");
        await wrapper.findAll(".accordion__trigger")[0].trigger("click");

        expect(openPanels(wrapper)).toStrictEqual([]);
    });

    it("opens whatever the page tells it to, which is what makes a section linkable", () => {
        // The deep-link case: the page reads an id out of the URL and passes it in.
        const wrapper = stack({ open: ["eschbach"] });

        expect(openPanels(wrapper)).toStrictEqual(["eschbach"]);
        expect(wrapper.text()).toContain("eschbach-shelf");
    });

    it("ignores an id no section has, rather than opening nothing visible", () => {
        // A stale URL, or a book re-tagged since the link was made.
        const wrapper = stack({ open: ["someone-who-left"] });

        expect(openPanels(wrapper)).toStrictEqual([]);
    });

    it("tells the page what was opened, so a URL can follow the reader", async () => {
        const wrapper = stack();

        await wrapper.findAll(".accordion__trigger")[2].trigger("click");

        // Indexed rather than `.at(-1)`: the tsconfig lib is ES2020, which has no Array.at.
        const emitted = wrapper.emitted("update:open") ?? [];
        expect(emitted[emitted.length - 1]).toStrictEqual([["eschbach"]]);
    });

    it("wires each header to its own panel", async () => {
        // The half a page cannot see and a screen reader depends on entirely.
        const wrapper = stack();
        const trigger = wrapper.findAll(".accordion__trigger")[0];

        expect(trigger.attributes("aria-expanded")).toBe("false");
        expect(trigger.attributes("aria-controls")).toBe("authors-accordion-panel-lovecraft");

        await trigger.trigger("click");

        expect(wrapper.findAll(".accordion__trigger")[0].attributes("aria-expanded")).toBe("true");
        const panel = wrapper.find("[role='region']");
        expect(panel.attributes("aria-labelledby")).toBe("authors-accordion-header-lovecraft");
    });

    it("prints the facts the consumer formatted, and nothing when there are none", () => {
        const wrapper = stack();
        const metas = wrapper.findAll(".accordion__meta").map(node => node.text());

        // Two sections carry facts; the third has none and gets no empty element.
        expect(metas).toStrictEqual(["6 Bücher", "1 Buch"]);
    });
});
