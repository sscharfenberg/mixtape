<script setup lang="ts">
/******************************************************************************
 * AddToPlaylistModal
 * "Which playlist?" — the dialog half of adding something to one, shared by every caller that
 * has a SET rather than a subject: the play queue's menu, and a listing's ticked rows.
 *
 * A MODAL RATHER THAN A POPOVER ROW, because this is a decision with an argument to it: which
 * playlist, and then a deliberate save. A popover list is for verbs that act at once (play,
 * clear), and a select nested inside one would be a popover inside a popover with a button that
 * must not close either.
 *
 * THE SHELL IS SHARED BUT THE BODY IS NOT, which is the whole reason this is its own component.
 * `useAddToPlaylist` already carries the rule about when save may be pressed; what was still
 * written twice was the form around it — legend, select, hint, footer button — and a second copy
 * of that is a second place for the disabled state and the form/button wiring to drift. The
 * caller supplies only what genuinely differs: the heading, the sentence, and what to add.
 *
 * `body` IS A FUNCTION, not a value, and evaluated at the press. The queue may advance while the
 * modal sits open, and a reader may tick another row on the table behind it — what gets added is
 * what is selected NOW, not what was selected when the dialog appeared.
 *
 * EVERY PLAYLIST IS OFFERED, unlike a detail page's hero, which hides the ones already holding
 * its subject. That narrowing is a server computation over one id; neither caller here has a
 * subject the server could run it for, and posting the whole set up just to draw a select would
 * be the request this dialog exists to make. The write is unaffected — it skips what a playlist
 * already holds and reports what landed — so the honest difference is that a reader may pick a
 * playlist and be told "already in there" rather than not being offered it.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Select from "Components/Form/Select/Select.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import type { AddToPlaylistBody } from "Composables/useAddToPlaylist";
import { useAddToPlaylist } from "Composables/useAddToPlaylist";

const props = defineProps<{
    /** The dialog's heading — each caller names what it is adding. */
    title: string;
    /**
     * What to add, in one of the endpoint's two shapes. A getter rather than a value: it is read
     * at the moment save is pressed, so a set that changed while the dialog stood open is the
     * set that gets written.
     */
    body: () => AddToPlaylistBody;
}>();

const emit = defineEmits<{
    /** The dialog wants to go away — cancelled, or done. */
    close: [];
    /** The write landed. Separate from `close` because a caller often has one more thing to do
     * on success only (a table clears its ticks), and closing happens either way. */
    saved: [];
}>();

const { t } = useI18n();

const { options, selected, saving, canSave, save } = useAddToPlaylist(() => props.body());

/**
 * The playlists as the Select wants them, in the reader's own order — `sort` is switched off
 * on the control, so what the server sent is what shows.
 */
const choices = computed(() => options.value.map(playlist => ({ value: playlist.id, label: playlist.name })));

/**
 * Save, and close only if the write succeeded.
 *
 * A failure leaves the dialog standing with the choice intact, so pressing save again is the
 * retry — closing on the attempt rather than on the result would hide the toast's bad news
 * behind a dialog that had already congratulated itself.
 */
function submit(): void {
    save(() => {
        emit("saved");
        emit("close");
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ title }}</template>

        <form id="add-to-playlist-form" class="form" @submit.prevent="submit">
            <!-- The caller's sentence. It is always some form of "how many am I about to add",
                 which is the one thing a reader cannot see at a glance behind the dialog. -->
            <form-legend :items="[{ slot: 'intro', icon: 'question' }]">
                <template #intro><slot name="intro" /></template>
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
            <Button variant="default" type="submit" form="add-to-playlist-form" :disabled="!canSave">
                <icon :name="saving ? 'refresh' : 'playlist_add'" :size="1" :rotate="saving" />
                <span>{{ t(saving ? "playlists.add.saving" : "playlists.add.submit") }}</span>
            </Button>
        </template>
    </modal>
</template>
