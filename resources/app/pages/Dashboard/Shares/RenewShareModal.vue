<script setup lang="ts">
/******************************************************************************
 * RenewShareModal
 * The confirmation in front of re-activating an expired share link — what saying yes does to a
 * URL that is already in somebody else's hands.
 *
 * IT IS REVOKING'S MIRROR, and it exists for the mirror-image reason. Revoking asks because the
 * consequence lands on a stranger who cannot be warned; this asks because the consequence lands
 * there too — a link the reader may have written off, in a chat they may have moved on from,
 * starts working again for anybody who still has it. Neither is a decision to take on a stray
 * click, and both are worth a sentence that says what happens to THEM rather than what happens
 * to the row.
 *
 * IT NAMES THE SUBJECT and the SEVEN DAYS, because those are the two things a reader is actually
 * deciding between: this album rather than its neighbour in the list, and a week rather than
 * whatever remained of the original (nothing did — the row was finished, so the clock starts
 * now; ShareController::renew says why that is the honest reading).
 *
 * A PATCH VIA `router`, like the revoke's DELETE and unlike the mint's `fetch`: the page has to
 * redraw, because the row moves out of the expired half and into the live one. That move IS the
 * feedback, and the flash rides back with the visit.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** The link being revived — its id is the capability, and the URL this PATCHes. */
    id: string;
    /** What it grants, for the sentence that names it: an album title, an artist, a playlist. */
    name: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/** Disables the button for the round trip, so a double press cannot send a second PATCH. */
const processing = ref(false);

/**
 * Re-activate it, and let the page redraw itself.
 *
 * The modal closes on success rather than immediately — closing first would leave the reader
 * looking at a row that is still in the expired list and then jumps out of it a moment later —
 * and it stays put on failure, where a closed dialog would leave a toast explaining something
 * whose subject is no longer on screen.
 */
function renew(): void {
    processing.value = true;
    router.patch(`/shares/${props.id}/renew`, undefined, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
        onFinish: () => (processing.value = false)
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("dashboard.shares.renew.header") }}</template>

        <p>{{ t("dashboard.shares.renew.body", { name }) }}</p>

        <template #footer>
            <Button variant="primary" type="button" :disabled="processing" @click="renew">
                <icon name="refresh" :size="1" />
                <span>{{ t("dashboard.shares.renew.confirm") }}</span>
            </Button>
        </template>
    </modal>
</template>
