<script setup lang="ts">
/******************************************************************************
 * QueuePlaylistModal
 * "Add everything in the queue to a playlist" — opened from the queue panel's menu.
 *
 * A MODAL RATHER THAN A ROW IN THE MENU, because this is a decision with an argument to it:
 * which playlist, and then a deliberate save. A popover list is for verbs that act at once
 * (play, clear), and a select nested inside one would be a popover inside a popover with a
 * button that must not close either.
 *
 * IT SENDS TRACK IDS, where a detail page's hero sends a subject and lets the server work the
 * tracks out. That difference is forced: the queue is CLIENT state — it lives in the browser
 * so the player can keep running while Inertia swaps pages — and the server's copy of it
 * (`player_states`) is written late on purpose, so asking that copy what is queued would
 * sometimes answer with the queue from a minute ago. The ids are read when save is pressed
 * rather than when the modal opens, so a track that finished and advanced the queue in the
 * meantime changes nothing.
 *
 * EVERY PLAYLIST IS OFFERED HERE, unlike the heroes, which hide the ones that already hold
 * their subject. The server cannot compute that for a queue it has not been sent, and posting
 * the whole queue up just to render a select would be the request this modal exists to make.
 * The write itself is unaffected — it skips what a playlist already holds and reports what
 * actually landed — so the honest difference is only that the reader may pick a playlist and
 * be told "already in there" rather than not being offered it at all.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Select from "Components/Form/Select/Select.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import { useAddToPlaylist } from "Composables/useAddToPlaylist";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { tracks } = usePlayerQueue();

const { options, selected, saving, canSave, save } = useAddToPlaylist(() => ({
    tracks: tracks.value.map(track => track.id)
}));

/**
 * The playlists as the Select wants them, in the reader's own order — `sort` is switched off
 * on the control, so what the server sent is what shows.
 */
const choices = computed(() => options.value.map(playlist => ({ value: playlist.id, label: playlist.name })));

/**
 * Save, and close only if the write succeeded.
 *
 * A failure leaves the modal standing with the choice intact, so pressing save again is the
 * retry — closing on the attempt rather than on the result would hide the toast's bad news
 * behind a dialog that had already congratulated itself.
 */
function submit(): void {
    save(() => emit("close"));
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("player.queue.addToPlaylist") }}</template>

        <form id="queue-playlist-form" class="form" @submit.prevent="submit">
            <!-- The count is the whole of what this says: the queue is on screen behind the
                 modal, so naming the tracks would repeat it, but "how many am I about to add"
                 is the one thing a reader cannot see at a glance. -->
            <form-legend :items="[{ slot: 'intro', icon: 'question' }]">
                <template #intro>{{ t("player.queue.addIntro", tracks.length) }}</template>
            </form-legend>

            <form-row :label="t('playlists.add.label')" addon-icon="playlist">
                <Select
                    :options="choices"
                    :selected="selected"
                    :placeholder="t('playlists.add.placeholder')"
                    :sort="false"
                    :disabled="saving"
                    @change="selected = $event"
                />
                <!-- FormRow's hint slot. It says the thing that makes a second press harmless,
                     which is exactly the worry a reader has with a button that adds. -->
                <template #text>{{ t("playlists.add.duplicatesHint") }}</template>
            </form-row>
        </form>

        <template #footer>
            <Button variant="default" type="submit" form="queue-playlist-form" :disabled="!canSave">
                <icon :name="saving ? 'refresh' : 'playlist_add'" :size="1" :rotate="saving" />
                <span>{{ t(saving ? "playlists.add.saving" : "playlists.add.submit") }}</span>
            </Button>
        </template>
    </modal>
</template>
