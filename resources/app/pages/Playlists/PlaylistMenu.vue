<script setup lang="ts">
/******************************************************************************
 * PlaylistMenu
 * The per-row actions menu on the playlists listing — everything you can do to one
 * playlist as a whole. One item so far (edit); rename / delete / play / enqueue land
 * here rather than as more buttons on the row, which is the point of it being a menu.
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
                <!-- PLACEHOLDER destination, like the row's own link: there is no edit page
                     yet. It becomes an Inertia <Link :href="…"> the moment there is one,
                     which is why it is already an anchor rather than a button. -->
                <a class="popover-list-item" href="https://www.google.com">
                    <icon name="settings" :size="1" />
                    {{ t("playlists.menu.edit") }}
                </a>
            </li>
        </ul>
    </pop-over>
</template>
