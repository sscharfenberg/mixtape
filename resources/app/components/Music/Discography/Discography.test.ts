import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import Discography from "./Discography.vue";
import type { DiscographyAlbum } from "./Discography.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Discography pages on the CLIENT: the caller hands over the whole set and this slices
 * it. That makes almost all of its behaviour deterministic and unit-testable, and two
 * bits of it are genuinely easy to get wrong:
 *
 *  - following a link from a 60-album genre to a 3-album one while on page 3 must not
 *    render an empty list (the set-change reset), and
 *  - that reset must NOT scroll, because the reader just arrived at the top of a new
 *    page — only a real page turn scrolls. The component tells the two apart with an
 *    internal flag, which is exactly the sort of thing that rots silently.
 */

/** Build an album with sensible defaults, overriding only what a test cares about. */
const album = (index: number, overrides: Partial<DiscographyAlbum> = {}): DiscographyAlbum => ({
    id: `album-${index}`,
    name: `Album ${index}`,
    year: 1997 + index,
    songs: 12,
    duration: 3600,
    coverUrl: `/covers/${index}.jpg`,
    href: `/music/albums/album-${index}`,
    ...overrides
});

/** A list of `count` albums. */
const albums = (count: number): DiscographyAlbum[] =>
    Array.from({ length: count }, (_unused, index) => album(index + 1));

/** Mount the discography over a set of albums. */
const discography = (props: { albums: DiscographyAlbum[]; pageSize?: number; showArtist?: boolean }) =>
    mountApp(Discography, { props });

/** The album names currently rendered. */
const namesIn = (wrapper: ReturnType<typeof discography>): string[] =>
    wrapper.findAll(".discography__name").map(node => node.text());

describe("Discography", () => {
    beforeEach(() => {
        resetInertia();
        // scrollIntoView does not exist in happy-dom; the scroll assertions spy on it.
        Element.prototype.scrollIntoView = vi.fn();
        vi.stubGlobal(
            "matchMedia",
            vi.fn(() => ({ matches: false }))
        );
    });

    it("lists the albums it is given, in the order the server sent them", () => {
        const wrapper = discography({ albums: albums(3) });

        expect(namesIn(wrapper)).toStrictEqual(["Album 1", "Album 2", "Album 3"]);
    });

    it("renders each row as a real link to the album's page", () => {
        // Links, not clickable divs — keyboard reachable and middle-clickable for free.
        const wrapper = discography({ albums: albums(2) });

        expect(wrapper.findAll("a").map(node => node.attributes("href"))).toStrictEqual([
            "/music/albums/album-1",
            "/music/albums/album-2"
        ]);
    });

    it("shows an empty message rather than an empty list", () => {
        const wrapper = discography({ albums: [] });

        expect(wrapper.find("ul").exists()).toBe(false);
        expect(wrapper.text()).toContain(translate("music.discography.empty"));
    });

    describe("the fact chips", () => {
        it("shows the year, song count and running time", () => {
            const wrapper = discography({ albums: [album(1, { year: 1997, songs: 12, duration: 3661 })] });
            const facts = wrapper.findAll(".discography__fact").map(node => node.text());

            expect(facts).toContain("1997");
            expect(facts).toContain("1:01:01");
        });

        it("drops the year for an untagged rip instead of showing an empty chip", () => {
            const wrapper = discography({ albums: [album(1, { year: null })] });

            expect(wrapper.findAll(".discography__fact").map(node => node.text())).not.toContain("");
            expect(wrapper.text()).not.toContain("null");
        });

        it("drops the running time rather than claiming 0:00", () => {
            const wrapper = discography({ albums: [album(1, { duration: null })] });

            expect(wrapper.findAll(".discography__fact").map(node => node.text())).not.toContain("0:00");
        });

        it("hides the artist by default, since an artist's own list would repeat it", () => {
            const wrapper = discography({ albums: [album(1, { artist: "Radiohead" })] });

            expect(wrapper.text()).not.toContain("Radiohead");
        });

        it("shows the artist when asked, as a genre's list needs", () => {
            const wrapper = discography({ albums: [album(1, { artist: "Radiohead" })], showArtist: true });

            expect(wrapper.text()).toContain("Radiohead");
        });

        it("drops the artist chip for a compilation filed under none", () => {
            const wrapper = discography({ albums: [album(1, { artist: null })], showArtist: true });

            expect(wrapper.findAll(".discography__fact").map(node => node.text())).not.toContain("");
        });
    });

    describe("client-side paging", () => {
        it("shows only the first page", () => {
            const wrapper = discography({ albums: albums(30), pageSize: 25 });

            expect(wrapper.findAll(".discography__item")).toHaveLength(25);
            expect(namesIn(wrapper)[0]).toBe("Album 1");
        });

        it("does not page a set that fits", () => {
            const wrapper = discography({ albums: albums(10), pageSize: 25 });

            expect(wrapper.findAll(".discography__item")).toHaveLength(10);
        });

        it("slices rather than requesting when the page changes", async () => {
            const wrapper = discography({ albums: albums(30), pageSize: 25 });

            wrapper.findComponent({ name: "DiscographyPagination" }).vm.$emit("update:page", 2);
            await nextTick();

            expect(namesIn(wrapper)).toStrictEqual(["Album 26", "Album 27", "Album 28", "Album 29", "Album 30"]);
            // The whole point: no round trip, so nothing reached the router.
            const { routerCalls } = await import("Testing/inertia");
            expect(routerCalls).toHaveLength(0);
        });

        it("scrolls the list back into view on a page turn", async () => {
            const wrapper = discography({ albums: albums(30), pageSize: 25 });

            wrapper.findComponent({ name: "DiscographyPagination" }).vm.$emit("update:page", 2);
            await nextTick();

            // The list, not the document — scrolling to the document top would hide the
            // hero and tab strip, i.e. the very thing that just changed.
            expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ block: "start", behavior: "auto" });
        });
    });

    describe("when the album set is replaced", () => {
        it("returns to page one, so a smaller set is not rendered empty", async () => {
            const wrapper = discography({ albums: albums(60), pageSize: 25 });
            wrapper.findComponent({ name: "DiscographyPagination" }).vm.$emit("update:page", 3);
            await nextTick();
            expect(namesIn(wrapper)[0]).toBe("Album 51");

            // Following a link from a 60-album genre to a 3-album one.
            await wrapper.setProps({ albums: albums(3) });

            expect(namesIn(wrapper)).toStrictEqual(["Album 1", "Album 2", "Album 3"]);
        });

        it("does not scroll on that reset, because the reader just arrived at the top", async () => {
            const wrapper = discography({ albums: albums(60), pageSize: 25 });
            wrapper.findComponent({ name: "DiscographyPagination" }).vm.$emit("update:page", 3);
            await nextTick();
            vi.mocked(Element.prototype.scrollIntoView).mockClear();

            await wrapper.setProps({ albums: albums(3) });
            await nextTick();

            expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
        });

        it("still scrolls on a genuine page turn after a reset", async () => {
            // The reset flag must be consumed, not left latched — otherwise the next
            // real page turn silently loses its scroll.
            const wrapper = discography({ albums: albums(60), pageSize: 25 });
            await wrapper.setProps({ albums: albums(60).map(entry => ({ ...entry })) });
            await nextTick();
            vi.mocked(Element.prototype.scrollIntoView).mockClear();

            wrapper.findComponent({ name: "DiscographyPagination" }).vm.$emit("update:page", 2);
            await nextTick();

            expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
        });
    });

    it("renders artwork twice, one instance per layout", () => {
        // A size in CoverImage carries its radius and frame width with it, so the row and
        // card layouts genuinely need separate instances rather than one resized by CSS.
        const wrapper = discography({ albums: [album(1)] });

        expect(wrapper.findAll(".discography__art--row")).toHaveLength(1);
        expect(wrapper.findAll(".discography__art--card")).toHaveLength(1);
    });
});
