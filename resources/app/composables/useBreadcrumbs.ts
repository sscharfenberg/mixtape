/******************************************************************************
 * useBreadcrumbs
 * The breadcrumb trail's single source of truth, ported from cantrip.me. State
 * lives at MODULE level (the same no-Pinia singleton pattern as useToast): a
 * page writes the trail in its own `<script setup>`, and the Breadcrumb
 * component — mounted once in FullLayout, nowhere near that page in the tree —
 * reads it back without any prop-drilling or provide/inject.
 *
 * The trail is deliberately declared by the page rather than derived from the
 * URL: only the page knows the *names* of the things in its path (a song's
 * title, its album) and which parents are actually reachable for this visitor.
 * main.ts clears it on every Inertia navigation start, so a page that forgets to
 * set crumbs shows none instead of inheriting the previous page's trail.
 *****************************************************************************/
import type { Ref } from "vue";
import { ref } from "vue";

/** A single breadcrumb item. Provide either `label` (raw string) or `labelKey` (i18n key). */
export type BreadcrumbItem = {
    /** Raw string label — used as-is, takes precedence over labelKey. */
    label?: string;
    /** i18n key — resolved by the Breadcrumb component via t(). Used when label is absent. */
    labelKey?: string;
    /** Optional named parameters passed to t(). Only relevant when using labelKey. */
    params?: Record<string, string>;
    /** Optional Inertia href. Omit for the current (last) item. */
    href?: string;
    /** Optional icon name (a sprite symbol id), rendered via <Icon>. */
    icon?: string;
};

/** Return type of the {@link useBreadcrumbs} composable. */
export type UseBreadcrumbsReturn = {
    /** The current trail, in display order — the last item is the page you are on. */
    crumbs: Ref<BreadcrumbItem[]>;
    /** Replace the trail wholesale (there is no incremental API on purpose — a page owns its full path). */
    setBreadcrumbs: (items: BreadcrumbItem[]) => void;
};

// Module-level state — all consumers share the same singleton instance.
const crumbs = ref<BreadcrumbItem[]>([]);

/**
 * Read / write the shared breadcrumb trail.
 *
 * Returns the module-level `crumbs` ref itself (not a copy), which is what lets
 * any page and the single Breadcrumb component agree on one list.
 *
 * @example
 * ```ts
 * const props = defineProps<{ song: SongDetail }>();
 * const { setBreadcrumbs } = useBreadcrumbs();
 * setBreadcrumbs([
 *     { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
 *     { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
 *     // raw string for a value that isn't in the catalog:
 *     { label: props.song.name }
 * ]);
 * ```
 */
export function useBreadcrumbs(): UseBreadcrumbsReturn {
    /** Swap in a new trail — called by a page during setup, and by main.ts (with []) on navigation. */
    function setBreadcrumbs(items: BreadcrumbItem[]) {
        crumbs.value = items;
    }

    return { crumbs, setBreadcrumbs };
}
