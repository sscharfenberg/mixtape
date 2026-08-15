<script setup lang="ts">
/******************************************************************************
 * PlaylistDeleteModal
 * The confirmation in front of deleting a playlist — what saying yes takes with it, and who
 * else notices.
 *
 * IT EXISTS BECAUSE THE CASCADE IS INVISIBLE FROM THE BUTTON. Deleting the playlist is the
 * obvious half and the reader has clearly asked for it; what the page cannot show is that
 * `shares` names the playlist with `cascadeOnDelete`, so every link ever minted from it dies
 * in the same statement. That link is in somebody else's chat window, the reader cannot tell
 * whether it is being listened to right now, and a re-mint is a DIFFERENT id — so there is no
 * putting it back and no way to tell the person holding it. The dialog therefore says what
 * happens to them, not what happens to the row.
 *
 * The row-level remove button next to it deliberately has NO dialog (owner's call), and the
 * asymmetry is the point: taking one entry out of a list costs a position that "add to
 * playlist" can restore in a click, while this is the one act on the page that nothing undoes.
 *
 * NO `onSuccess` CLOSE, unlike RevokeShareModal, and for a reason worth stating: the server
 * answers with a redirect to the listing, so the whole page — this dialog included — is
 * replaced by Inertia. Emitting `close` first would only race that. `onFinish` still resets
 * the button, which is what a FAILED delete needs: the dialog stays, explains itself through
 * the flash, and can be pressed again.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

const props = defineProps<{
    /** The playlist being deleted — its id is the URL this sends to. */
    id: string;
    /** Its name, for the sentence that names what is about to go. */
    name: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/**
 * Whether the DELETE is in flight: swaps the button's icon for a spinner and disables it, so
 * a second press cannot send a second request against a row the first one is already removing.
 */
const processing = ref(false);

/**
 * Delete it, and let the server decide where the reader lands.
 *
 * `router.delete` rather than a `fetch`, for the same reason revoking a share uses one: this
 * wants the page redrawn and the flash carried back, which is exactly what an Inertia visit
 * does and what a bare fetch would leave us to do by hand.
 */
function destroy(): void {
    processing.value = true;
    router.delete(`/playlists/${props.id}`, {
        onFinish: () => (processing.value = false)
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("playlists.delete.header") }}</template>

        <p>{{ t("playlists.delete.body", { name }) }}</p>

        <!-- The share consequence as a legend item rather than another paragraph: it is the
             half the reader did not ask for and cannot see from the button, so it needs to
             read as a warning rather than as more prose. -->
        <form-legend :items="[{ slot: 'shares', icon: 'warning' }]">
            <template #shares>{{ t("playlists.delete.warning") }}</template>
        </form-legend>

        <template #footer>
            <Button variant="default" type="button" :disabled="processing" @click="destroy">
                <!-- The spinner takes the ICON's place rather than sitting beside it, so the
                     button keeps its width and the label does not shift under the pointer
                     that is still on it. -->
                <loading-spinner v-if="processing" :size="1" />
                <icon v-else name="delete" :size="1" />
                <span>{{ t("playlists.delete.confirm") }}</span>
            </Button>
        </template>
    </modal>
</template>
