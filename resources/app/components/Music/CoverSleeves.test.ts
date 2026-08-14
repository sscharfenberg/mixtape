import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import CoverSleeves from "./CoverSleeves.vue";

/*
 * The rule that decides how many sleeves are fanned, and in what DOM order — which is the
 * whole reason this is a component rather than three lines in each caller.
 *
 * It is the rule, not the drawing, that has to be right. On a genre page half of all artists
 * have exactly one album and only a third have three or more, so the ONE-cover stack is the
 * common case and the three-cover fan is the exception; every wrong answer is therefore
 * visible on most of the page at once — a repeated sleeve, a placeholder wedged in beside
 * two real covers, or a fan that silently drops a cover the server sent.
 *
 * The middle sleeve's DOM ORDER is tested too, and it is not fussiness: the three overlap,
 * and the one painted last is the one on top. Ordering by z-index instead would work until
 * the fan sat inside a stacking context, which is the kind of thing that breaks long after
 * the change that caused it.
 *
 * These tests came over from GenreArtists.test.ts when the fan was extracted.
 */

/** Mount the fan over a list of covers. */
const fan = (covers: string[]) => mountApp(CoverSleeves, { props: { covers, title: "Radiohead" } });

/** The position modifier of each rendered sleeve, in DOM order. */
const positions = (wrapper: ReturnType<typeof fan>): string[] =>
    wrapper.findAll(".cover-sleeves__sleeve").map(node => {
        const modifier = node.classes().find(name => name.startsWith("cover-sleeves__sleeve--"));

        return (modifier ?? "").replace("cover-sleeves__sleeve--", "");
    });

/** The `src` of each sleeve's image, with null for a placeholder. */
const sources = (wrapper: ReturnType<typeof fan>): (string | null)[] =>
    wrapper
        .findAll(".cover-sleeves__sleeve")
        .map(node => (node.find("img").exists() ? node.find("img").attributes("src") ?? null : null));

describe("CoverSleeves", () => {
    describe("the fan degrades honestly", () => {
        it("fans three sleeves when there are three covers", () => {
            expect(positions(fan(["/covers/1", "/covers/2", "/covers/3"]))).toStrictEqual([
                "left",
                "right",
                "middle"
            ]);
        });

        it("paints the middle sleeve last, so it is the one on top", () => {
            // DOM order IS the stacking order here — see the note above.
            // Index arithmetic rather than .at(): the project's tsconfig targets ES2020.
            const drawn = positions(fan(["/covers/1", "/covers/2", "/covers/3"]));

            expect(drawn[drawn.length - 1]).toBe("middle");
        });

        it("fans two sleeves for two covers rather than padding to three", () => {
            expect(positions(fan(["/covers/1", "/covers/2"]))).toStrictEqual(["left", "right"]);
        });

        it("shows one sleeve straight on for a single cover — the commonest case", () => {
            expect(positions(fan(["/covers/1"]))).toStrictEqual(["single"]);
        });

        it("never repeats a cover to fill the fan", () => {
            expect(sources(fan(["/covers/only"]))).toStrictEqual(["/covers/only"]);
        });

        it("falls back to a single placeholder when there is no artwork at all", () => {
            const wrapper = fan([]);

            expect(positions(wrapper)).toStrictEqual(["single"]);
            // A placeholder, not a broken image pointing at nothing. It is also what makes a
            // HeroSection draw its dashed "no artwork on file" square, since that keys off
            // the slot holding no <img>.
            expect(sources(wrapper)).toStrictEqual([null]);
        });

        it("caps the fan at three even if the server ever sent more", () => {
            expect(fan(["/1", "/2", "/3", "/4", "/5"]).findAll(".cover-sleeves__sleeve")).toHaveLength(3);
        });

        it("renders the covers it was given, in the order they arrived", () => {
            // The server shuffles; this component must not re-order, or the randomness would
            // be applied twice and the middle sleeve would not be the middle one sent.
            expect(sources(fan(["/a", "/b", "/c"]))).toStrictEqual(["/a", "/c", "/b"]);
        });
    });

    it("is hidden from assistive tech, since it only ever illustrates a name beside it", () => {
        expect(fan(["/covers/1"]).attributes("aria-hidden")).toBe("true");
    });

    it("re-fans when the covers change, rather than keeping the first set", () => {
        // A card in a keyed list can be handed a different subject's covers without being
        // remounted; the sleeves are a computed over the prop, which is what makes that work.
        const wrapper = fan(["/covers/1"]);

        return wrapper.setProps({ covers: ["/a", "/b"] }).then(() => {
            expect(positions(wrapper)).toStrictEqual(["left", "right"]);
            expect(sources(wrapper)).toStrictEqual(["/a", "/b"]);
        });
    });
});
