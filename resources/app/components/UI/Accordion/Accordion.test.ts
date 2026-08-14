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
    {
        id: "lovecraft",
        label: "H.P. Lovecraft",
        // The two shapes a fact can take, on one section: a count wearing its noun AFTER it and
        // a measurement wearing its name BEFORE it.
        facts: [
            { icon: "audiobook", value: "6", unit: "Bücher", title: "6 Bücher" },
            { icon: "duration", label: "Spielzeit", value: "12:30:04", title: "Spielzeit 12:30:04" }
        ]
    },
    { id: "king", label: "Stephen King", facts: [{ icon: "audiobook", value: "1", unit: "Buch", title: "1 Buch" }] },
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

    describe("the fact chips", () => {
        it("gives each fact its own chip, and draws none where there are none", () => {
            // One chip per fact, not one element holding a joined sentence — which is what this
            // reads as, and why the words could not then be hidden on a phone.
            const wrapper = stack();

            // UNSPACED on purpose: a chip is a flex row, so the distance between its icon, its
            // word and its number is a `gap` rather than a word space. Written with no space
            // here so the day somebody puts one in the markup — where `condense` would eat it
            // anyway — this says which mechanism is doing the spacing.
            expect(wrapper.findAll(".accordion__fact").map(node => node.text().replace(/\s+/gu, ""))).toStrictEqual([
                "6Bücher",
                "Spielzeit12:30:04",
                "1Buch"
            ]);
            // The third section carries none and gets no empty row to hold a gap open.
            expect(wrapper.findAll(".accordion__facts")).toHaveLength(2);
        });

        it("puts the word on the side that fact's grammar wants", () => {
            /*
             * A count reads as a phrase ("3 Bücher") and a
             * measurement as a labelled fact ("Spielzeit 40:51:45"), so one word trails the
             * value and the other leads it. Asserted on the rendered ORDER, which is the only
             * thing that can tell the two apart — both are the same element and both vanish at
             * the same width.
             */
            const chips = stack().findAll(".accordion__fact");
            const parts = (index: number) =>
                chips[index].findAll("span").map(node => node.text().replace(/\s+/gu, " "));

            expect(parts(0)).toStrictEqual(["6", "Bücher"]);
            expect(parts(1)).toStrictEqual(["Spielzeit", "12:30:04"]);
        });

        it("names the whole fact on the chip, since a word may be hidden and the icon is mute", () => {
            // Below 480px the chip is an icon and a number; without this a reader would meet a
            // bare "6" with nothing saying six of what.
            expect(stack().findAll(".accordion__fact").map(node => node.attributes("title"))).toStrictEqual([
                "6 Bücher",
                "Spielzeit 12:30:04",
                "1 Buch"
            ]);
        });
    });
});
