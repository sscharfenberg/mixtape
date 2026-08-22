import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import UserMenu from "./UserMenu.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The account popover in the header, and the only place in the app whose contents depend on
 * whether anyone is signed in. That makes its two gates worth pinning, because both fail in
 * the direction nobody tests by hand:
 *
 *   - a GUEST must not be offered "dashboard" or "logout". Neither would work, and the
 *     logout item posts, so an accidentally-rendered one is a 419 rather than a redirect.
 *     Everyone developing this app is signed in, so the guest half is the half that rots.
 *   - a SIGNED-IN reader must not be offered "log in" or "forgot password". Harmless, but
 *     it is the same one-line `v-if` inverted, so a mistake tends to affect both.
 *
 * The forgot-password item carries a second gate: the `resetPasswords` feature flag. The
 * route does not exist when the feature is off, so the item has to be gone, not disabled.
 *
 * LOGOUT IS A BUTTON, not a link — `method="post"` + `as="button"`. Rendered as a plain
 * anchor it becomes a GET a browser (or a link prefetcher) may follow on its own, which is
 * the classic way an app logs people out by accident.
 *
 * The popover's own open/close behaviour belongs to PopOver (tested) and, for the real
 * top-layer dance, to Playwright.
 */

/** Mount the menu for a guest or for a signed-in reader. */
const menu = (props: Record<string, unknown> = {}) => {
    setPage({
        props: {
            auth: { user: null },
            features: { resetPasswords: true },
            supportedLocales: ["de", "en"],
            csrfToken: "token",
            ...props
        }
    });

    return mountApp(UserMenu, { attachTo: document.body });
};

/** Mount the menu with somebody signed in. */
const signedIn = (props: Record<string, unknown> = {}) => menu({ auth: { user: { name: "Ashaltiriak" } }, ...props });

/** The visible text of every item in the popover list. */
const items = (wrapper: ReturnType<typeof menu>): string[] =>
    wrapper.findAll(".popover-list-item").map(item => item.text());

describe("UserMenu", () => {
    beforeEach(() => {
        resetInertia();
        /*
         * ThemeSwitch reads <meta name="color-scheme"> at setup and THROWS without one, so
         * mounting the menu requires it — the Blade shell provides it in the app. Installing
         * it here is what keeps a missing tag a loud failure rather than something the menu's
         * tests would have to stub away.
         */
        document.head.querySelector("meta[name='color-scheme']")?.remove();
        const meta = document.createElement("meta");
        meta.setAttribute("name", "color-scheme");
        meta.setAttribute("content", "light dark");
        document.head.append(meta);
        document.body.innerHTML = "";
    });

    it("offers a guest the way in, and nothing that needs an account", () => {
        const shown = items(menu());

        expect(shown).toContain(translate("header.userMenu.login"));
        expect(shown).toContain(translate("header.userMenu.loginHelp"));
        expect(shown).not.toContain(translate("header.userMenu.dashboard"));
        expect(shown).not.toContain(translate("header.userMenu.logout"));
    });

    it("offers a signed-in reader their settings and the way out, and nothing to sign in with", () => {
        const shown = items(signedIn());

        expect(shown).toContain(translate("header.userMenu.dashboard"));
        expect(shown).toContain(translate("header.userMenu.logout"));
        expect(shown).not.toContain(translate("header.userMenu.login"));
        expect(shown).not.toContain(translate("header.userMenu.loginHelp"));
    });

    it("hides the password-recovery item where the feature is switched off", () => {
        // The route does not exist then, so a disabled item would still be a dead one.
        expect(items(menu({ features: { resetPasswords: false } }))).not.toContain(
            translate("header.userMenu.loginHelp")
        );
    });

    it("logs out with a POST button rather than a link a prefetcher could follow", () => {
        const logout = signedIn()
            .findAllComponents({ name: "InertiaLink" })
            .find(link => link.props("href") === "/logout")!;

        expect(logout.props("method")).toBe("post");
        expect(logout.props("as")).toBe("button");
        expect(logout.element.tagName).toBe("BUTTON");
    });

    it("lights the trigger up while somebody is signed in", () => {
        // The header's only persistent signal that the session is live.
        expect(menu().findComponent({ name: "PopOver" }).props("classString")).not.toContain(
            "popover-button--highlighted"
        );
        expect(signedIn().findComponent({ name: "PopOver" }).props("classString")).toContain(
            "popover-button--highlighted"
        );
    });

    it("carries both preference toggles below the account items, whoever is looking", () => {
        for (const wrapper of [menu(), signedIn()]) {
            expect(wrapper.findComponent({ name: "LanguageSwitch" }).exists()).toBe(true);
            expect(wrapper.findComponent({ name: "ThemeSwitch" }).exists()).toBe(true);
        }
    });

    describe("the listening-history entry", () => {
        /*
         * Gated on the `hasPlays` shared prop, like the shares entry beside it and for the same
         * reason: a menu item leading to a page that can only say "you have not listened to
         * anything yet" is a promise the page cannot keep, and a fresh account would meet it
         * before it ever met the music. Whether that prop is true is the server's answer and is
         * pinned in tests/Feature/History/HistoryPageTest.php; what is here is what the menu
         * does with it.
         */

        /** Every divider in the list — they are rules, not items, so they are counted apart. */
        const dividers = (wrapper: ReturnType<typeof menu>) => wrapper.findAll(".popover-list__divider");

        it("stays away until this reader has listened to something", () => {
            expect(items(signedIn({ hasPlays: false }))).not.toContain(translate("header.userMenu.history"));
        });

        it("appears once they have, in the run of places to go", () => {
            // It sits with the dashboard rather than apart from it: everything between the two
            // rules is somewhere to GO, and the history is one of those. The rules are the menu's
            // only structure — one under logout, one above the preference toggles — so their
            // count does not move with this entry.
            const wrapper = signedIn({ hasPlays: true });

            expect(items(wrapper)).toContain(translate("header.userMenu.history"));
            expect(dividers(wrapper)).toHaveLength(2);
            expect(dividers(signedIn({ hasPlays: false }))).toHaveLength(2);
        });

        it("is drawn after the account entries, and logout before all of them", () => {
            /*
             * THE ORDER IS THE FEATURE HERE. Logout ends the session and everything else is
             * navigation, so it leads and is fenced off by a rule — never one of a run of
             * similar-looking links, which is how a menu earns a misclick that costs a queue and
             * a sign-in. The rest read outwards from the account: settings, the links it has
             * sent, the devices it exports for, then what has been listened to.
             */
            const wrapper = signedIn({ hasPlays: true, hasShares: true, hasExportPresets: true });

            // Sliced because `items` also matches LanguageSwitch's two entries, which sit below
            // the second rule and are a preference rather than a place to go.
            expect(items(wrapper).slice(0, 5)).toStrictEqual([
                translate("header.userMenu.logout"),
                translate("header.userMenu.dashboard"),
                translate("header.userMenu.shares"),
                translate("header.userMenu.presets"),
                translate("header.userMenu.history")
            ]);
        });

        it("is never offered to a guest, whatever the prop says", () => {
            // The prop is false for a guest without the database being asked, but the entry
            // carries its own `user` check — a link to a page behind `auth` must not depend on
            // one boolean being right.
            expect(items(menu({ hasPlays: true }))).not.toContain(translate("header.userMenu.history"));
        });

        it("leads to the history page, warmed on hover", () => {
            // Safe to prefetch, unlike the two auth forms above it: the far end is a page one
            // only reads (CLAUDE.md → the prefetch rule).
            const link = signedIn({ hasPlays: true })
                .findAllComponents({ name: "InertiaLink" })
                .find(candidate => candidate.props("href") === "/history")!;

            expect(link).toBeDefined();
            expect(link.props("prefetch")).toBe(true);
        });
    });

    it("names the nav landmark and the trigger, whose glyph carries no words", () => {
        const wrapper = menu();

        expect(wrapper.find("nav").attributes("aria-label")).toBe(translate("header.userMenu.nav"));
        expect(wrapper.findComponent({ name: "PopOver" }).props("ariaLabel")).toBe(translate("header.userMenu.open"));
    });
});
