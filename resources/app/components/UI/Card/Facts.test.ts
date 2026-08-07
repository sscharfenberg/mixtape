import { describe, expect, it, vi } from "vitest";
import { mountApp } from "Testing/mount";
import Facts, { type Fact } from "./Facts.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Facts owns the PAIRS: it buckets a flat list into cards and drops the holes. Both halves
 * are load-bearing in a way the rendered page never shows.
 *
 * The FILTER is the reason a detail page can pass one fixed list. Tags in a ripped
 * collection are sparse, so a page that rendered every pair would read as broken — a dozen
 * "Genre: —" rows — rather than as untagged. A zero is covered separately below: it is not
 * a hole, and while today's `string | null` makes `"0"` truthy anyway, the moment a caller
 * hands over a number (or the filter is "tidied" into a numeric check) the distinction
 * becomes real and silent — a count of 0 simply stops being shown.
 *
 * The BUCKETING relies on Map insertion order, which is what makes the caller's own order
 * the only thing deciding the layout — there is no second list of group titles to keep in
 * sync. A Record would reorder integer-like keys and an array-of-groups would need that
 * second list; neither failure is visible until a group title happens to be numeric.
 *
 * Card and CardGroup are exercised through this, deliberately: they have no logic of their
 * own beyond "a title renders only when there is one", and Facts is the only thing that
 * ever constructs them.
 */

/** A pair, defaulted around something present and ungrouped. */
const fact = (overrides: Partial<Fact> = {}): Fact => ({ key: "album", label: "Album", value: "OK Computer", ...overrides });

/** Mount over a list of pairs. */
const facts = (list: Fact[], wideGroups = false) => mountApp(Facts, { props: { facts: list, wideGroups } });

/** The rendered cards' titles, in document order — "" for the untitled catch-all. */
const cardTitles = (wrapper: ReturnType<typeof facts>): string[] =>
    wrapper.findAll(".card").map(card => {
        const title = card.find(".card__title");

        return title.exists() ? title.text() : "";
    });

describe("Facts", () => {
    it("buckets pairs into one card per group, in the order the caller first named them", () => {
        const wrapper = facts([
            fact({ key: "a", group: "Datei" }),
            fact({ key: "b", group: "Musik" }),
            fact({ key: "c", group: "Datei" })
        ]);

        expect(cardTitles(wrapper)).toStrictEqual(["Datei", "Musik"]);
        // …and the third pair rejoined the FIRST card rather than opening a third.
        expect(wrapper.findAll(".card")[0].findAll(".fact-pair")).toHaveLength(2);
    });

    it("keeps first-seen order even when the group titles are numeric", () => {
        // The reason this is a Map and not a plain object: `{ "2": …, "10": … }` iterates
        // 2 before 10 whatever order they were written in, so a year-grouped list would
        // silently reorder itself.
        const wrapper = facts([fact({ key: "a", group: "10" }), fact({ key: "b", group: "2" })]);

        expect(cardTitles(wrapper)).toStrictEqual(["10", "2"]);
    });

    it("drops a pair with nothing to say, so a page can pass one fixed list", () => {
        const wrapper = facts([fact({ key: "a", label: "Album" }), fact({ key: "b", label: "Komponist", value: null }), fact({ key: "c", label: "Verlag", value: "" })]);

        expect(wrapper.findAll(".fact-pair")).toHaveLength(1);
        expect(wrapper.text()).not.toContain("Komponist");
        expect(wrapper.text()).not.toContain("Verlag");
    });

    it('keeps a value of "0", which is a fact and not a hole', () => {
        const wrapper = facts([fact({ key: "a", label: "Alben", value: "0" })]);

        expect(wrapper.findAll(".fact-pair")).toHaveLength(1);
        expect(wrapper.text()).toContain("Alben");
    });

    it("makes a group that the filter emptied disappear along with its pairs", () => {
        const wrapper = facts([fact({ key: "a", group: "Datei", value: null }), fact({ key: "b", group: "Musik" })]);

        expect(cardTitles(wrapper)).toStrictEqual(["Musik"]);
    });

    it("renders an untitled group as a card with no heading rather than an empty one", () => {
        const wrapper = facts([fact({ key: "a" })]);

        expect(wrapper.findAll(".card")).toHaveLength(1);
        expect(wrapper.find(".card__title").exists()).toBe(false);
    });

    it("widens only the card that actually holds something long, and only when asked", () => {
        const list = [fact({ key: "a", group: "Datei", wide: true }), fact({ key: "b", group: "Musik" })];

        // Opt-in: without `wideGroups` a `wide` pair changes nothing about its card.
        expect(facts(list).findAll(".card--wide")).toHaveLength(0);

        const opted = facts(list, true);
        expect(opted.findAll(".card--wide")).toHaveLength(1);
        expect(opted.find(".card--wide").text()).toContain("Datei");
    });

    it("marks the set as a list even though the markers are styled away", () => {
        // Safari/VoiceOver drops list semantics from a list with no markers, so the role
        // has to be stated. Without it a screen reader stops announcing "1 of 7".
        const wrapper = facts([fact()]);

        expect(wrapper.find(".facts").attributes("role")).toBe("list");
        expect(wrapper.find(".fact-pair").element.tagName).toBe("LI");
    });
});
