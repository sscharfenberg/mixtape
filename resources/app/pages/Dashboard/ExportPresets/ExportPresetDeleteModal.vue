<script setup lang="ts">
/******************************************************************************
 * ExportPresetDeleteModal
 * The confirmation in front of deleting an export preset — and, more usefully, what deleting
 * one does NOT do.
 *
 * IT EXISTS TO SAY WHAT IS SAFE, which is the opposite of RevokeShareModal's job. Revoking a
 * link breaks something already in somebody else's hands; deleting a preset costs the reader
 * four values they can retype. The risk here is the reader's UNCERTAINTY — a preset is
 * something an export was made with, so the obvious fear is that the .m3u files already sitting
 * on a USB stick depend on it. They do not: an exported file holds the values, never the row.
 * Saying so is worth a dialog, where "are you sure?" alone would not be.
 *
 * IT NAMES THE DEVICE, because a list of presets is a list of similar-looking rows and the
 * whole risk of a per-row delete button is hitting the neighbour of the one you meant.
 *
 * THE DELETE IS AN INERTIA VISIT, so the page redraws with one row fewer and the flash rides
 * back with it. That redraw is load-bearing here beyond the missing row: deleting the preset
 * that holds the default flag passes it to another, and the marker has to be seen moving.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** The preset being deleted — its id is the URL this deletes. */
    id: string;
    /** What the reader called the device, for the sentence that names it. */
    name: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/** Disables the button for the round trip, so a double press cannot send a second DELETE. */
const processing = ref(false);

/**
 * Delete it, and let the page redraw itself.
 *
 * The modal closes on success rather than immediately: closing first would return the reader to
 * a list still showing the row, which then vanishes under them a moment later. It stays put on
 * failure too — a dialog that closed on an error would leave the toast explaining something the
 * reader can no longer see the subject of.
 */
function remove(): void {
    processing.value = true;
    router.delete(`/dashboard/export-presets/${props.id}`, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
        onFinish: () => (processing.value = false)
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("dashboard.presets.deleteModal.header") }}</template>

        <p>{{ t("dashboard.presets.deleteModal.intro", { name }) }}</p>

        <template #footer>
            <Button variant="default" type="button" :disabled="processing" @click="remove">
                <icon name="delete" :size="1" />
                <span>{{ t("dashboard.presets.deleteModal.confirm") }}</span>
            </Button>
        </template>
    </modal>
</template>
