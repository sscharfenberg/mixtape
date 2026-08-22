import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import WelcomeIntro from "./WelcomeIntro.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The landing page's explanation and the buttons beside it.
 *
 * WHAT IS ACTUALLY UNDER TEST IS THE TWO ARMS, because `/` is not a guests-only route
 * — the logo in the header points here, so a signed-in reader lands on this page too. "Anmelden"
 * shown to somebody who is already signed in reads as a broken session, and PHP cannot see that:
 * HomeController sends the same props either way, and the decision is made client-side off the
 * shared `auth.user` prop. This is the layer that can answer it.
 *
 * THE PROSE IS ASSERTED AS THE CATALOGUE'S, not as literal German. What matters here is that the
 * block says the two things it exists to say — what MixTape is, and that a shared link needs no
 * account — so the cases grab the strings by key. Rewording the copy is a content decision and
 * should not fail a test; DROPPING a paragraph is a different thing, and does.
 *
 * THE MEMBER ARM'S ORDER IS THE OTHER HALF, and it is this layer's alone: the server sends raw
 * seconds per area and says nothing about which comes first. Ordering by HOURS rather than by
 * plays is what makes an audiobook reader's page open with audiobooks, and the tie-break exists
 * for the account that has listened to nothing yet.
 *
 * The prefetch question is not testable here and is deliberately left to the component's banner:
 * the mock `<Link>` records no prefetch behaviour. The rule is that `/login` is a password form
 * and must never be warmed (CLAUDE.md → the prefetch rule).
 */

/**
 * Mount the block as a guest or as a member.
 *
 * The library defaults to holding both kinds, since that is the case the ordering is about; a
 * test that cares about an empty area zeroes its count.
 */
const intro = (
    user: { id: string; name: string } | null,
    listening: Record<string, number> = { music: 0, audiobook: 0 },
    tracks: { musicTracks: number; audiobookTracks: number } = { musicTracks: 500, audiobookTracks: 500 }
) => {
    setPage({ props: { auth: { user } } });

    return mountApp(WelcomeIntro, { props: { listening, ...tracks } });
};

/** A signed-in reader, minimal — only `auth.user` being non-null is read. */
const member = { id: "user-1", name: "Ash" };

describe("WelcomeIntro", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("says what this is and how a person gets in, to anyone", () => {
        const text = intro(null).text();

        expect(text).toContain(translate("home.intro"));
        expect(text).toContain(translate("home.invite"));
    });

    it("sends a visitor with no session to the login form", () => {
        const link = intro(null).find("a");

        expect(link.attributes("href")).toBe("/login");
        expect(link.text()).toContain(translate("home.signIn"));
    });

    it("offers a signed-in reader the areas themselves instead", () => {
        // The whole point: a member who clicked the logo must not be offered a way to sign in
        // to the session they are already in.
        const wrapper = intro(member);
        const links = wrapper.findAll("a");

        expect(links.map(link => link.attributes("href"))).toStrictEqual(["/music", "/audiobooks"]);
        expect(wrapper.text()).not.toContain(translate("home.signIn"));
    });

    it("puts the area they have listened to most first", () => {
        // Hours, not plays: a chapter runs half an hour against a song's three minutes, so a
        // reader who spends every evening on audiobooks has fewer plays and far more time.
        const links = intro(member, { music: 3_600, audiobook: 90_000 }).findAll("a");

        expect(links.map(link => link.attributes("href"))).toStrictEqual(["/audiobooks", "/music"]);
        expect(links[0].text()).toContain(translate("home.allAudiobooks"));
    });

    it("falls back to the bigger area when nothing has been listened to", () => {
        // A fresh account. The tie-break is the rule a sign-in lands by, so the page and the
        // login agree about what this instance is mostly about.
        const wrapper = intro(member, { music: 0, audiobook: 0 }, { musicTracks: 12, audiobookTracks: 900 });

        expect(wrapper.findAll("a").map(link => link.attributes("href"))).toStrictEqual([
            "/audiobooks",
            "/music"
        ]);
    });

    it("does not offer an area the library holds nothing of", () => {
        // A button to a page with nothing on it is the broken promise the site menu refuses to
        // make; this instance may legitimately hold one kind and not the other.
        const wrapper = intro(member, { music: 0, audiobook: 0 }, { musicTracks: 500, audiobookTracks: 0 });

        expect(wrapper.findAll("a").map(link => link.attributes("href"))).toStrictEqual(["/music"]);
    });

    it("marks each arm with the glyph that area already uses", () => {
        expect(iconNames(intro(null))).toStrictEqual(["login"]);
        expect(iconNames(intro(member))).toStrictEqual(["music", "audiobook"]);
    });

    it("draws one call to action for a guest, whatever the library holds", () => {
        // Both arms rendering at once is the failure mode of turning the conditional into a
        // pair of `v-if`s — and a guest is offered the login however much media there is.
        expect(intro(null).findAll("a")).toHaveLength(1);
        expect(intro(null, { music: 0, audiobook: 0 }, { musicTracks: 0, audiobookTracks: 0 }).findAll("a")).toHaveLength(1);
    });

    it("keeps its English copy in step with its German", () => {
        // The build type-checks keys against de.json, which cannot catch an entry that exists in
        // both files but was only translated in one. Reading both here is the cheap guard.
        expect(translate("home.intro", "en")).not.toBe(translate("home.intro", "de"));
        expect(translate("home.signIn", "en")).not.toBe(translate("home.signIn", "de"));
    });
});
