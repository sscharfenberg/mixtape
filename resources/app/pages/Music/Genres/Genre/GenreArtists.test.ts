import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import GenreArtists from "./GenreArtists.vue";
import type { GenreArtist } from "./GenreArtists.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artist cards: the numbers each one carries about its artist WITHIN this genre, and
 * that the whole card is a link to them.
 *
 * The fan of sleeves each card leads with is NOT tested here any more — it became
 * CoverSleeves when the playlist hero wanted the same object, and its degradation rule
 * (three, two, one, placeholder) went with it to CoverSleeves.test.ts. What is left on this
 * side is the one thing that would still break silently: that the covers the server sent
 * reach it at all.
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

/** The `src` of every sleeve image on the page, in DOM order. */
const sleeveSources = (wrapper: ReturnType<typeof tab>): (string | null)[] =>
    wrapper.findAll(".cover-sleeves__sleeve").map(node => (node.find("img").exists() ? node.find("img").attributes("src") ?? null : null));

describe("GenreArtists", () => {
    beforeEach(() => {
        resetInertia();
    });

    describe("the cover fan", () => {
        it("hands each card's own covers to the fan, in the order the server sent them", () => {
            // The seam, and all this side owns: how those covers are laid out — and what
            // happens when there are two, one or none — is CoverSleeves' rule and its test.
            // The middle one comes last in the DOM, which is why the order reads /a /c /b.
            const wrapper = tab([artist({ covers: ["/a", "/b", "/c"] })]);

            expect(sleeveSources(wrapper)).toStrictEqual(["/a", "/c", "/b"]);
        });

        it("gives every card its own fan, so two artists cannot share one", () => {
            const wrapper = tab([
                artist({ id: "a", covers: ["/one"] }),
                artist({ id: "b", covers: ["/two"] })
            ]);

            expect(sleeveSources(wrapper)).toStrictEqual(["/one", "/two"]);
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
