import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import type { SiteArea } from "Composables/useSiteAreas";
import { useSiteAreas } from "Composables/useSiteAreas";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's areas, which stopped being a constant list on 2026-08-08: every one of
 * them now has to earn its place, and each earns it from a different fact.
 *
 * Music and audiobooks come from the SERVER (only it knows what the library holds),
 * playlists from either of those, and Now Playing from the QUEUE — client state that
 * changes with no request to notice it, which is why that entry appears and disappears
 * mid-visit and why it sits last, where coming and going shifts nothing after it.
 *
 * Read through a real i18n instance rather than a stubbed `t`, so a renamed catalog key
 * fails here instead of rendering as its own name.
 */

/** Minimal host component: runs the composable and exposes its result to the test. */
const probe = defineComponent({
    setup: () => ({ areas: useSiteAreas() }),
    template: "<div />"
});

/**
 * The areas as they would render for a given library, in order.
 *
 * Mounted rather than called: the composable uses `useI18n()`, which needs a component
 * instance — the same probe the first version of this file used, for the same reason.
 */
const areasFor = (library: Record<string, boolean>, locale: "de" | "en" = "de"): SiteArea[] => {
    setPage({ props: { library } });

    return (mountApp(probe, { locale }).vm as unknown as { areas: SiteArea[] }).areas;
};

const FULL = { music: true, audiobook: true };

describe("useSiteAreas", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
    });

    it("offers music, audiobooks and playlists to a library that has both kinds", () => {
        expect(areasFor(FULL).map(area => area.href)).toStrictEqual(["/music", "/audiobooks", "/playlists"]);
    });

    it("hides an area the library has nothing in", () => {
        // An empty area is a link to a page that says nothing — and an instance
        // legitimately holds one kind and not the other.
        expect(areasFor({ music: true, audiobook: false }).map(area => area.href)).toStrictEqual([
            "/music",
            "/playlists"
        ]);
        expect(areasFor({ music: false, audiobook: true }).map(area => area.href)).toStrictEqual([
            "/audiobooks",
            "/playlists"
        ]);
    });

    it("offers nothing at all to an empty library, playlists included", () => {
        // A playlist of an empty library is not a feature, it is a form nobody can fill.
        expect(areasFor({ music: false, audiobook: false })).toStrictEqual([]);
    });

    it("hides everything when the server said nothing, rather than throwing", () => {
        // Any response that omits the prop — an older page, an error view — must leave
        // the header standing.
        expect(areasFor({} as Record<string, boolean>)).toStrictEqual([]);
    });

    it("adds Now Playing once the queue holds something, and drops it again", () => {
        expect(areasFor(FULL).map(area => area.href)).not.toContain("/now-playing");

        usePlayerQueue().enqueue({
            id: "a",
            name: "Track a",
            artist: null,
            album: null,
            coverUrl: null,
            duration: 100,
            href: "/music/songs/a",
            streamUrl: "/music/songs/a/stream"
        });

        expect(areasFor(FULL).map(area => area.href)).toStrictEqual([
            "/music",
            "/audiobooks",
            "/playlists",
            "/now-playing"
        ]);

        usePlayerQueue().clear();
        expect(areasFor(FULL).map(area => area.href)).not.toContain("/now-playing");
    });

    it("labels every area from the catalog, in the active locale", () => {
        expect(areasFor(FULL, "en").map(area => area.label)).toStrictEqual([
            translate("header.siteMenu.music", "en"),
            translate("header.siteMenu.audiobooks", "en"),
            translate("header.siteMenu.playlists", "en")
        ]);
    });

    it("names an icon for each, since the compact menu shows glyphs alone", () => {
        expect(areasFor(FULL).map(area => area.icon)).toStrictEqual(["music", "audiobook", "playlist"]);
    });
});
