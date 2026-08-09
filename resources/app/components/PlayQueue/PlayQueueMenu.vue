<script setup lang="ts">
/**************************************************************************
 * PlayQueueMenu
 * The queue panel's action menu — the three-dot trigger in its header and the
 * list behind it.
 *
 * A menu rather than the bare trash button this replaces. Clearing the queue is
 * destructive and irreversible, and a lone icon in a 280px header strip is far
 * too easy to hit on the way to something else; behind a menu it takes a
 * deliberate second click and is named in words instead of guessed from a glyph.
 *
 * Its own component rather than markup inside PlayQueue because it is where the
 * queue's other verbs live, and those bring state and handlers with them — "add
 * everything to a playlist" is the first, and it brought a modal. Keeping them
 * out of the panel leaves that file about *drawing the queue*, which is already
 * the longer job.
 *
 * VERBS ONLY, since 2026-08-06. Repeat lived here first and has moved to the
 * player bar's settings popover (Player/PlayerSettings), where it sits beside
 * shuffle as one of two play MODES. Two reasons it does not belong in this menu:
 * this panel is behind a toggle on a phone and gone entirely once the queue is
 * emptied, so a setting you want while listening was hidden in both cases — and a
 * harmless toggle sat one row above a destructive verb.
 *
 * THE TWO VERBS SIT IN THAT ORDER — add first, clear last — and are separated by
 * the destructive one's own colour. Clearing is the entry a mis-aimed click must
 * not land on, so it is the one furthest from the trigger.
 *
 * It reads the queue directly rather than taking props or emitting events: the
 * composable is a module singleton, so going through the panel would be
 * prop-drilling between two components that can both simply ask.
 **************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import QueuePlaylistModal from "Components/PlayQueue/QueuePlaylistModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { PlaylistOption } from "Composables/useAddToPlaylist";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const { t } = useI18n();
const { clear } = usePlayerQueue();
const page = usePage();

/** DOM id of this menu's popover — the handle used to close it by hand below. */
const REFERENCE = "playQueueActions";

/** Whether the "add to playlist" modal is open. Mounted only while it is, like the export one. */
const addingToPlaylist = ref(false);

/**
 * Whether the reader has a playlist to add to at all.
 *
 * The entry is hidden rather than disabled when they have none: a menu row that opens a modal
 * offering an empty select is a worse answer than no row, and making the first playlist happens
 * in the Playlists area, not here. Read off the shared `playlists` prop, tolerantly — a
 * response that omits it hides the row rather than throwing inside the panel.
 */
const hasPlaylists = computed(() => ((page.props.playlists as PlaylistOption[] | undefined) ?? []).length > 0);

/**
 * Open the modal, and put this popover away first.
 *
 * The explicit close is what the `clear` entry below does NOT need and this one does: emptying
 * the queue unmounts the whole panel, taking an open popover out of the DOM with it, while
 * this entry leaves the panel exactly where it was. Without it the menu would still be hanging
 * open behind the modal, and again when the modal closes.
 */
function openPlaylistModal(): void {
    document.getElementById(REFERENCE)?.hidePopover();
    addingToPlaylist.value = true;
}
</script>

<template>
    <pop-over
        icon="more"
        :reference="REFERENCE"
        class-string="popover-button--rounded popover-button--subtle"
        :aria-label="t('player.queue.actions')"
        width="26ch"
    >
        <ul class="popover-list">
            <li v-if="hasPlaylists">
                <button type="button" class="popover-list-item" @click="openPlaylistModal">
                    <icon name="playlist_add" :size="1" />
                    {{ t("player.queue.addToPlaylist") }}
                </button>
            </li>
            <li>
                <!-- No explicit popover close: an empty queue unmounts the whole panel,
                     and taking an open popover out of the DOM removes it from the top
                     layer with it. The entry above, which LEAVES the queue standing,
                     closes it by hand. -->
                <button type="button" class="popover-list-item popover-list-item--caution" @click="clear">
                    <icon name="delete" :size="1" />
                    {{ t("player.queue.clear") }}
                </button>
            </li>
        </ul>
    </pop-over>

    <!-- Mounted only while open, like the playlist page's export modal: the select then starts
         empty every time rather than remembering the last playlist added to. -->
    <queue-playlist-modal v-if="addingToPlaylist" @close="addingToPlaylist = false" />
</template>
