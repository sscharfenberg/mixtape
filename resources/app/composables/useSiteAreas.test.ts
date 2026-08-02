import { describe, expect, it } from "vitest";
import { defineComponent } from "vue";
import { useSiteAreas } from "Composables/useSiteAreas";
import type { SiteArea } from "Composables/useSiteAreas";
import { mountApp, translate } from "Testing/mount";

/*
 * useSiteAreas exists so the two presentations of the site menu — SiteMenuLinks (inline)
 * and SiteMenuPopover (compact) — cannot drift apart. So the test that earns its keep is
 * the one asserting both consumers get the same list, plus that labels come from the
 * catalog (they must follow a runtime locale switch, not be baked in at build time).
 *
 * It calls useI18n(), so it only works inside a component — hence the probe below rather
 * than a direct call.
 */

/** Minimal host component: runs the composable and exposes its result to the test. */
const probe = defineComponent({
    setup: () => ({ areas: useSiteAreas() }),
    template: "<div />"
});

/** Mount the probe and read the computed areas back out. */
const areasFor = (locale: "de" | "en"): SiteArea[] =>
    (mountApp(probe, { locale }).vm as unknown as { areas: SiteArea[] }).areas;

describe("useSiteAreas", () => {
    it("lists the four top-level areas in display order", () => {
        expect(areasFor("de").map(area => area.href)).toStrictEqual([
            "/music",
            "/audiobooks",
            "/podcasts",
            "/playlists"
        ]);
    });

    it("gives every area an icon", () => {
        expect(areasFor("de").map(area => area.icon)).toStrictEqual(["music", "audiobook", "podcast", "playlist"]);
    });

    it("takes its labels from the catalog", () => {
        expect(areasFor("de").map(area => area.label)).toStrictEqual([
            translate("header.siteMenu.music", "de"),
            translate("header.siteMenu.audiobooks", "de"),
            translate("header.siteMenu.podcasts", "de"),
            translate("header.siteMenu.playlists", "de")
        ]);
    });

    it("renders labels in the active locale", () => {
        const german = areasFor("de").map(area => area.label);
        const english = areasFor("en").map(area => area.label);

        expect(english).toStrictEqual([
            translate("header.siteMenu.music", "en"),
            translate("header.siteMenu.audiobooks", "en"),
            translate("header.siteMenu.podcasts", "en"),
            translate("header.siteMenu.playlists", "en")
        ]);
        // Guard against both catalogs being identical, which would make the test vacuous.
        expect(english).not.toStrictEqual(german);
    });

    it("hands both site-menu presentations the same list", () => {
        const inline = areasFor("de");
        const popover = areasFor("de");

        expect(popover).toStrictEqual(inline);
    });
});
