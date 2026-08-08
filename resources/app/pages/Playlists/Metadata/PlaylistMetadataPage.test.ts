import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia, routerCalls } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PlaylistMetadataPage from "./PlaylistMetadataPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * One page serves both directions, and `playlist` — null or present — is the ONLY signal
 * telling it which. That switch is entirely client-side: PlaylistMetadataController's own
 * tests pin the props it sends and the rules it applies, but nothing on the server can see
 * what the page then does with a null playlist versus a real one.
 *
 * The assertion that matters most is WHERE THE FORM POSTS AND HOW. Get that wrong and the
 * failure is not cosmetic in either direction: a create that PUTs to `/playlists` hits no
 * route, and an edit that POSTs to `/playlists` creates a second playlist instead of saving
 * the first — a bug that looks like "saving does nothing" while quietly duplicating rows.
 *
 * The rest is the same shape: the two labels, the seeded fields (including a null
 * description, which must arrive as an empty textarea and never the string "null"), and the
 * breadcrumb, which names the ACTION because a playlist has no page of its own yet.
 */

/** The prop as the edit route sends it. */
const playlist = (overrides: Record<string, unknown> = {}) => ({
    id: "playlist-1",
    name: "Sunday morning",
    description: "Quiet things.",
    ...overrides
});

/** Mount the page in create mode (no playlist) or over one. */
const page = (prop: ReturnType<typeof playlist> | null = null) =>
    mountApp(PlaylistMetadataPage, { props: { playlist: prop } });

/**
 * Submit the form and return the request the mock recorded.
 *
 * Indexed rather than `.at(-1)`: tsconfig's `lib` predates `Array.prototype.at`, so the
 * tidier call does not type-check here.
 */
const submit = async (wrapper: ReturnType<typeof page>) => {
    await wrapper.find("form").trigger("submit");

    return routerCalls[routerCalls.length - 1];
};

describe("PlaylistMetadataPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    describe("creating", () => {
        it("posts to the collection", async () => {
            expect(await submit(page())).toMatchObject({ method: "post", url: "/playlists" });
        });

        it("starts with both fields empty", () => {
            const wrapper = page();

            expect(wrapper.find<HTMLInputElement>("#name").element.value).toBe("");
            expect(wrapper.find<HTMLTextAreaElement>("#description").element.value).toBe("");
        });

        it("heads and labels itself as a create", () => {
            const text = page().text();

            expect(text).toContain(translate("playlists.form.createHeadline"));
            expect(text).toContain(translate("playlists.form.createSubmit"));
            expect(text).toContain(translate("playlists.form.createIntro"));
        });

        it("says nothing about editing", () => {
            expect(page().text()).not.toContain(translate("playlists.form.editSubmit"));
        });
    });

    describe("editing", () => {
        it("PUTs to the playlist itself", async () => {
            // A POST here would create a second playlist rather than save this one.
            expect(await submit(page(playlist({ id: "abc-123" })))).toMatchObject({
                method: "put",
                url: "/playlists/abc-123"
            });
        });

        it("seeds both fields from the playlist", () => {
            const wrapper = page(playlist());

            expect(wrapper.find<HTMLInputElement>("#name").element.value).toBe("Sunday morning");
            expect(wrapper.find<HTMLTextAreaElement>("#description").element.value).toBe("Quiet things.");
        });

        it("renders a missing description as an empty field, not as null", () => {
            const wrapper = page(playlist({ description: null }));

            expect(wrapper.find<HTMLTextAreaElement>("#description").element.value).toBe("");
        });

        it("heads and labels itself as an edit, and says the tracks are left alone", () => {
            const text = page(playlist()).text();

            expect(text).toContain(translate("playlists.form.editHeadline"));
            expect(text).toContain(translate("playlists.form.editSubmit"));
            expect(text).toContain(translate("playlists.form.editIntro"));
        });

        it("says nothing about creating", () => {
            expect(page(playlist()).text()).not.toContain(translate("playlists.form.createSubmit"));
        });
    });

    it("keeps the shared field labels in both directions", () => {
        for (const prop of [null, playlist()]) {
            const text = page(prop).text();

            expect(text).toContain(translate("playlists.form.nameLabel"));
            expect(text).toContain(translate("playlists.form.descriptionLabel"));
            expect(text).toContain(translate("playlists.form.cancel"));
        }
    });

    it("offers a way back to the listing that writes nothing", () => {
        expect(page(playlist()).find('a[href="/playlists"]').exists()).toBe(true);
    });

    it("publishes a trail whose last crumb names the action", () => {
        // The playlist itself has no page yet, so a crumb for it would point nowhere.
        page(playlist());

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.playlists", href: "/playlists", icon: "playlist" },
            { label: translate("playlists.form.editHeadline"), icon: "settings" }
        ]);
    });
});
