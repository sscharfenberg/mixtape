import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import WelcomeIntro from "./WelcomeIntro.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The landing page's explanation and its one button.
 *
 * WHAT IS ACTUALLY UNDER TEST IS THE BUTTON'S TWO ARMS, because `/` is not a guests-only route
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
 * The prefetch question is not testable here and is deliberately left to the component's banner:
 * the mock `<Link>` records no prefetch behaviour. The rule is that `/login` is a password form
 * and must never be warmed (CLAUDE.md → the prefetch rule).
 */

/** Mount the block as a guest or as a member — the only input it has. */
const intro = (user: { id: string; name: string } | null) => {
    setPage({ props: { auth: { user } } });

    return mountApp(WelcomeIntro);
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

    it("sends a signed-in reader into the collection instead", () => {
        // The whole point: a member who clicked the logo must not be offered a way to sign in
        // to the session they are already in.
        const link = intro(member).find("a");

        expect(link.attributes("href")).toBe("/music");
        expect(link.text()).toContain(translate("home.browse"));
        expect(link.text()).not.toContain(translate("home.signIn"));
    });

    it("marks each arm with the glyph that area already uses", () => {
        expect(iconNames(intro(null))).toStrictEqual(["login"]);
        expect(iconNames(intro(member))).toStrictEqual(["music"]);
    });

    it("draws exactly one call to action, whoever is reading", () => {
        // Two buttons would mean both arms rendered — the failure mode of turning the
        // conditional into a pair of `v-if`s.
        expect(intro(null).findAll("a")).toHaveLength(1);
        expect(intro(member).findAll("a")).toHaveLength(1);
    });

    it("keeps its English copy in step with its German", () => {
        // The build type-checks keys against de.json, which cannot catch an entry that exists in
        // both files but was only translated in one. Reading both here is the cheap guard.
        expect(translate("home.intro", "en")).not.toBe(translate("home.intro", "de"));
        expect(translate("home.signIn", "en")).not.toBe(translate("home.signIn", "de"));
    });
});
