/******************************************************************************
 * useSiteAreas
 * The header's top-level navigation areas (Music / Audiobooks / Podcasts /
 * Playlists), shared by the two presentations of the site menu — SiteMenuLinks
 * (inline, desktop) and SiteMenuPopover (compact) — so the list can't drift
 * between them. Labels are computed from the i18n catalog so they follow a
 * runtime locale switch.
 *****************************************************************************/
import { computed } from "vue";
import type { ComputedRef } from "vue";
import { useI18n } from "vue-i18n";

export interface SiteArea {
    /** Destination path (the areas themselves are still to be built). */
    href: string;
    /** Translated, human-readable label. */
    label: string;
    /** Sprite icon name. */
    icon: string;
}

/** Reactive list of the header's top-level areas, in display order. */
export function useSiteAreas(): ComputedRef<SiteArea[]> {
    const { t } = useI18n();

    return computed(() => [
        { href: "/music", label: t("header.siteMenu.music"), icon: "music" },
        { href: "/audiobooks", label: t("header.siteMenu.audiobooks"), icon: "audiobook" },
        { href: "/podcasts", label: t("header.siteMenu.podcasts"), icon: "podcast" },
        { href: "/playlists", label: t("header.siteMenu.playlists"), icon: "playlist" }
    ]);
}
