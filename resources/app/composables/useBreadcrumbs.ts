/******************************************************************************
 * useBreadcrumbs
 * The breadcrumb trail's writer, ported from cantrip.me and since rebuilt on
 * Inertia's layout props. A page declares its own trail in `<script setup>`;
 * Breadcrumb — mounted once in FullLayout, nowhere near that page in the tree —
 * receives it as a prop, with no prop-drilling in between.
 *
 * The trail is deliberately declared by the page rather than derived from the
 * URL: only the page knows the *names* of the things in its path (a song's
 * title, its album) and which parents are actually reachable for this visitor.
 *
 * Why layout props rather than a module-level ref: the trail has to be emptied
 * between pages, or a page that declares none would inherit the previous one's
 * path — and *when* that emptying happens is the whole experience. Clearing it
 * ourselves on Inertia's `start` event blanks it the instant a link is clicked,
 * so the <nav> unmounts and the content jumps up while the request is still in
 * flight, then jumps back when the new page declares its own. Inertia resets
 * layout props inside `swapComponent` instead
 * — the exact moment the incoming page replaces the outgoing one — so the old
 * trail stays put for the whole visit and is *replaced*, never blanked.
 *
 * `setLayoutProps` merges rather than overwrites, which is invisible here: that
 * reset wipes the store between pages, and a page hands over its complete path
 * in a single call. The typed `breadcrumbs` key comes from the `layoutProps`
 * augmentation in resources/types/inertia.d.ts.
 *****************************************************************************/
import { setLayoutProps } from "@inertiajs/vue3";

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
    /** Publish the page's full path (there is no incremental API on purpose — a page owns its whole trail). */
    setBreadcrumbs: (items: BreadcrumbItem[]) => void;
};

/**
 * Declare the current page's breadcrumb trail.
 *
 * Kept as a composable rather than a bare `setLayoutProps` call at every call
 * site so pages name the thing they are setting — and so the trail's shape stays
 * defined in one place, next to the reasoning above.
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
    /** Publish the trail as a layout prop, where FullLayout picks it up and hands it to Breadcrumb. */
    function setBreadcrumbs(items: BreadcrumbItem[]) {
        setLayoutProps({ breadcrumbs: items });
    }

    return { setBreadcrumbs };
}
