/******************************************************************************
 * useSiteAreas
 * The header's top-level navigation areas, shared by the two presentations of the
 * site menu — SiteMenuLinks (inline, desktop) and SiteMenuPopover (compact) — so
 * the list can't drift between them. Labels are computed from the i18n catalog so
 * they follow a runtime locale switch.
 *
 * EVERY AREA IS CONDITIONAL, and each on a different fact:
 *
 *   - MUSIC and AUDIOBOOKS on whether the library holds any. An empty area is a
 *     link to a page that says nothing, and this instance can legitimately hold one
 *     kind and not the other — the flags come from the server, which is the only
 *     thing that knows (`library` in the shared props).
 *   - PLAYLISTS on there being anything to make a playlist OF. A playlist of an
 *     empty library is not a feature, it is a form nobody can fill in.
 *   - NOW PLAYING on the queue holding something, which is CLIENT state and cannot
 *     come from the server: the queue lives in the browser (usePlayerQueue) so the
 *     player can keep running while Inertia swaps pages, and it changes without any
 *     request to notice it. This link therefore appears and disappears mid-visit,
 *     which is the point of it.
 *
 * It sits LAST for that reason. A link that comes and goes shifts whatever follows
 * it, so the one entry that does is the one with nothing after it.
 *
 * There is deliberately no podcast area: a podcast is something you listen to on the
 * service that publishes it, not a folder of mp3s.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import type { ComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import { usePlayerQueue } from "Composables/usePlayerQueue";

export interface SiteArea {
    /** Destination path. */
    href: string;
    /** Translated, human-readable label. */
    label: string;
    /** Sprite icon name. */
    icon: string;
}

/** Reactive list of the header's top-level areas, in display order. */
export function useSiteAreas(): ComputedRef<SiteArea[]> {
    const { t } = useI18n();
    const { isEmpty } = usePlayerQueue();

    return computed(() => {
        // Read tolerantly: a page rendered before the prop existed — or any response
        // that omits it — should hide the areas rather than throw on the header.
        const library = usePage().props.library;
        const hasMusic = library?.music === true;
        const hasAudiobooks = library?.audiobook === true;

        return [
            ...(hasMusic ? [{ href: "/music", label: t("header.siteMenu.music"), icon: "music" }] : []),
            ...(hasAudiobooks
                ? [{ href: "/audiobooks", label: t("header.siteMenu.audiobooks"), icon: "audiobook" }]
                : []),
            ...(hasMusic || hasAudiobooks
                ? [{ href: "/playlists", label: t("header.siteMenu.playlists"), icon: "playlist" }]
                : []),
            ...(isEmpty.value
                ? []
                : [{ href: "/now-playing", label: t("header.siteMenu.nowPlaying"), icon: "now_playing" }])
        ];
    });
}
