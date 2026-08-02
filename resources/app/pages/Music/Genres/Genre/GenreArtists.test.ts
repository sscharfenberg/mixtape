import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import GenreArtists from "./GenreArtists.vue";
import type { GenreArtist } from "./GenreArtists.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artist cards, and above all the rule that decides how many sleeves a card fans.
 *
 * That rule is the reason this component exists rather than three lines in the page: in
 * this collection half of all artists have exactly one album and only a third have three
 * or more, so the ONE-cover card is the common case and the three-cover fan is the
 * exception. Every wrong answer here is visible on most of the page at once — a repeated
 * sleeve, a placeholder wedged in beside two real covers, or a fan that silently drops a
 * cover the server sent.
 *
 * The middle sleeve's DOM ORDER is tested too, and it is not fussiness: the three overlap,
 * and the one painted last is the one on top. Ordering by z-index instead would work until
 * these cards sit inside a stacking context, which is the kind of thing that breaks long
 * after the change that caused it.
 */

/** An artist with sensible defaults; tests override only what they are about. */
const artist = (overrides: Partial<GenreArtist> = {}): GenreArtist => ({
    id: "artist-1",
    name: "Radiohead",
    songs: 24,
    albums: 2,
    duration: 6296,
    covers: ["/covers/1", "/covers/2", "/covers/3"],
    href: "/music/artists/artist-1",
    ...overrides
});

/** Mount the tab over a list of artists. */
const tab = (artists: GenreArtist[]) => mountApp(GenreArtists, { props: { artists } });

/** The position modifier of each rendered sleeve, in DOM order. */
const sleevePositions = (wrapper: ReturnType<typeof tab>): string[] =>
    wrapper.findAll(".genre-artists__sleeve").map(node => {
        const modifier = node.classes().find(name => name.startsWith("genre-artists__sleeve--"));

        return (modifier ?? "").replace("genre-artists__sleeve--", "");
    });

/** The `src` of each sleeve's image, with null for a placeholder. */
const sleeveSources = (wrapper: ReturnType<typeof tab>): (string | null)[] =>
    wrapper.findAll(".genre-artists__sleeve").map(node => node.find("img").exists() ? node.find("img").attributes("src") ?? null : null);

describe("GenreArtists", () => {
    beforeEach(() => {
        resetInertia();
    });

    describe("the cover fan degrades honestly", () => {
        it("fans three sleeves when the artist has three covers", () => {
            const wrapper = tab([artist({ covers: ["/covers/1", "/covers/2", "/covers/3"] })]);

            expect(sleevePositions(wrapper)).toStrictEqual(["left", "right", "middle"]);
        });

        it("paints the middle sleeve last, so it is the one on top", () => {
            // DOM order IS the stacking order here — see the note above.
            const wrapper = tab([artist()]);

            // Index arithmetic rather than .at(): the project's tsconfig targets ES2020.
            const positions = sleevePositions(wrapper);
            expect(positions[positions.length - 1]).toBe("middle");
        });

        it("fans two sleeves for a two-album artist rather than padding to three", () => {
            const wrapper = tab([artist({ covers: ["/covers/1", "/covers/2"] })]);

            expect(sleevePositions(wrapper)).toStrictEqual(["left", "right"]);
        });

        it("shows one sleeve straight on for a one-album artist — the commonest card", () => {
            const wrapper = tab([artist({ covers: ["/covers/1"] })]);

            expect(sleevePositions(wrapper)).toStrictEqual(["single"]);
        });

        it("never repeats a cover to fill the fan", () => {
            const wrapper = tab([artist({ covers: ["/covers/only"] })]);

            expect(sleeveSources(wrapper)).toStrictEqual(["/covers/only"]);
        });

        it("falls back to a single placeholder when no album carries artwork", () => {
            const wrapper = tab([artist({ covers: [] })]);

            expect(sleevePositions(wrapper)).toStrictEqual(["single"]);
            // A placeholder, not a broken image pointing at nothing.
            expect(sleeveSources(wrapper)).toStrictEqual([null]);
        });

        it("caps the fan at three even if the server ever sent more", () => {
            const wrapper = tab([artist({ covers: ["/1", "/2", "/3", "/4", "/5"] })]);

            expect(wrapper.findAll(".genre-artists__sleeve")).toHaveLength(3);
        });

        it("renders the covers it was given, in the order they arrived", () => {
            // The server shuffles; this component must not re-order, or the randomness
            // would be applied twice and the middle sleeve would not be the middle one sent.
            const wrapper = tab([artist({ covers: ["/a", "/b", "/c"] })]);

            expect(sleeveSources(wrapper)).toStrictEqual(["/a", "/c", "/b"]);
        });

        it("hides the fan from assistive tech, since the name follows it in the same link", () => {
            const wrapper = tab([artist()]);

            expect(wrapper.find(".genre-artists__fan").attributes("aria-hidden")).toBe("true");
        });
    });

    describe("the card's facts", () => {
        it("shows the artist's name", () => {
            expect(tab([artist({ name: "Portishead" })]).find(".genre-artists__name").text()).toBe("Portishead");
        });

        it("formats the raw duration as a clock rather than seconds", () => {
            const facts = tab([artist({ duration: 6296 })]).findAll(".genre-artists__fact").map(node => node.text());

            expect(facts).toContain("1:44:56");
            expect(facts.join(" ")).not.toContain("6296");
        });

        it("pluralises the counts", () => {
            const one = tab([artist({ songs: 1, albums: 1 })]).findAll(".genre-artists__fact").map(node => node.text());
            expect(one).toContain("1 Song");
            expect(one).toContain("1 Album");
        });

        it("uses the plural form for many", () => {
            const many = tab([artist({ songs: 24, albums: 2 })]).findAll(".genre-artists__fact").map(node => node.text());
            expect(many).toContain("24 Songs");
            expect(many).toContain("2 Alben");
        });

        it("still shows a zero count rather than dropping the chip", () => {
            // Unlike a missing tag, 0 is an answer: the artist has no album filed under
            // this genre even though their songs are tagged with it.
            const wrapper = tab([artist({ albums: 0 })]);

            expect(wrapper.findAll(".genre-artists__fact").map(node => node.text())).toContain("0 Alben");
        });

        it("makes the whole card a link to the artist", () => {
            const link = tab([artist()]).find(".genre-artists__link");

            expect(link.element.tagName).toBe("A");
            expect(link.attributes("href")).toBe("/music/artists/artist-1");
        });
    });

    describe("the list as a whole", () => {
        it("renders one card per artist, in the order given", () => {
            const wrapper = tab([
                artist({ id: "a", name: "Blur" }),
                artist({ id: "b", name: "Radiohead" })
            ]);

            expect(wrapper.findAll(".genre-artists__name").map(node => node.text())).toStrictEqual([
                "Blur",
                "Radiohead"
            ]);
        });

        it("is a real list, so assistive tech announces how many there are", () => {
            const wrapper = tab([artist()]);

            expect(wrapper.find("ul").exists()).toBe(true);
            expect(wrapper.find("ul").attributes("aria-label")).toContain("1");
        });

        it("says so when no artist calls this their main genre", () => {
            const wrapper = tab([]);

            expect(wrapper.find("ul").exists()).toBe(false);
            expect(wrapper.text()).toBe(translate("music.genre.noArtists"));
        });
    });
});
