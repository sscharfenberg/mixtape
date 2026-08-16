<script setup lang="ts">
/******************************************************************************
 * QueuePlaylistModal
 * "Add everything in the queue to a playlist" — opened from the queue panel's menu.
 *
 * WHAT THIS COMPONENT IS, now that the dialog itself is shared: the knowledge that the set being
 * added is THE QUEUE. That is the one decision here, and it is not a small one.
 *
 * IT SENDS TRACK IDS, where a detail page's hero sends a subject and lets the server work the
 * tracks out. That difference is forced: the queue is CLIENT state — it lives in the browser so
 * the player can keep running while Inertia swaps pages — and the server's copy of it
 * (`player_states`) is written late on purpose, so asking that copy what is queued would
 * sometimes answer with the queue from a minute ago.
 *
 * THE IDS GO OUT IN QUEUE ORDER, because a playlist built from the queue has to play like the
 * queue. And they are read when save is pressed rather than when the dialog opens — the getter
 * below is called at the press — so a track that finished and advanced the queue in the meantime
 * changes nothing about what is written.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import AddToPlaylistModal from "Components/Playlists/AddToPlaylistModal.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { tracks } = usePlayerQueue();
</script>

<template>
    <add-to-playlist-modal
        :title="t('player.queue.addToPlaylist')"
        :body="() => ({ tracks: tracks.map(track => track.id) })"
        @close="emit('close')"
    >
        <!-- The count is the whole of what this says: the queue is on screen behind the dialog,
             so naming the tracks would repeat it. -->
        <template #intro>{{ t("player.queue.addIntro", tracks.length) }}</template>
    </add-to-playlist-modal>
</template>
