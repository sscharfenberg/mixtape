<script setup lang="ts">
/******************************************************************************
 * PlaylistMenu
 * The per-row actions menu on the playlists listing — everything you can do to one
 * playlist as a whole. One item so far, "edit metadata", which opens the same form the
 * playlist was created through (Playlists/Metadata); delete / play / enqueue land here
 * rather than as more buttons on the row, which is the point of it being a menu.
 *
 * It sits BESIDE the row's <a>, never inside it. An <a> may not contain interactive
 * content, and a <button> nested in one is not merely invalid markup — the click runs
 * the button and follows the link, and assistive tech announces one control where there
 * are two. So the row's anchor and this trigger are siblings; where the trigger sits
 * visually is the row's styling problem, not a reason to nest it.
 *
 * `reference` is derived from the playlist's id, not left to PopOver's random default.
 * That default exists precisely so several popovers on a page do not collide, and a
 * listing is many popovers — but the id is already unique per row, and a STABLE
 * reference is one a test can name and one that survives a re-render (the random default
 * is regenerated on remount, so the DOM id a trigger points at would change under it).
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";

const props = defineProps<{
    /** The playlist this menu acts on — its id keys the popover, its name names the trigger. */
    playlist: { id: string; name: string };
}>();

const { t } = useI18n();

/**
 * DOM id of this row's popover, and the CSS anchor name its panel positions against.
 *
 * Prefixed rather than the bare uuid: PopOver builds a CSS `anchor-name` as "--" + this,
 * and a name that reads `--playlist-menu-<uuid>` says what it belongs to in devtools,
 * where a bare uuid says nothing.
 */
const reference = computed<string>(() => `playlist-menu-${props.playlist.id}`);
</script>

<template>
    <!-- `--rounded --subtle`: the quiet variant, a flat grey pill rather than the site
         menu's navy gradient. The same pair SubjectMenu passes, and for the same reason —
         this trigger opens a PANEL's own menu, so it should sit quietly inside it. -->
    <pop-over
        icon="more"
        :reference="reference"
        class-string="popover-button--rounded popover-button--subtle"
        :aria-label="t('playlists.menu.actions', { name: playlist.name })"
        width="20ch"
    >
        <ul class="popover-list">
            <li>
                <!-- The metadata form, over this playlist. A real Inertia <Link>, so the visit is
                     client-side like every other navigation.

                     NO `prefetch`, AND THAT IS MEASURED (2026-08-10). It had one, on the fair
                     argument that warming two fields and no query is cheap. What it actually
                     bought was the flakiest failure in the suite: a click that outruns the hover
                     timer sends its own request, so the prefetch is neither cached nor consumed —
                     and when its response lands, Inertia applies it to the page you are NOW on
                     (`Response.handlePrefetch()` calls `handle()` whenever the URL matches the
                     current location), which RE-CREATES the page component. Caught at 10ms
                     resolution: the `<form>` element was replaced 20ms after a field had been
                     typed into, and the save then wrote the value the server had sent.

                     A form is the worst place to buy 150ms that way — the reader is about to sit
                     there for seconds, and the page being rebuilt underneath them costs their
                     caret and (before PlaylistMetadataPage started remembering its fields) their
                     typing. That `useRemember` stays as the belt to this braces: a swap from
                     anywhere else is then harmless too. -->
                <Link class="popover-list-item" :href="`/playlists/${playlist.id}/edit`">
                    <icon name="settings" :size="1" />
                    {{ t("playlists.menu.editMetadata") }}
                </Link>
            </li>
        </ul>
    </pop-over>
</template>
