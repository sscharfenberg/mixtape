<script setup lang="ts">
/******************************************************************************
 * RevokeShareModal
 * The confirmation in front of revoking a share link — "are you sure?", and what saying yes
 * actually does to whoever is holding the link.
 *
 * IT EXISTS BECAUSE THE CONSEQUENCE IS SOMEBODY ELSE'S. Most destructive buttons in an app
 * cost the person pressing them something they can see; this one silently breaks a link that
 * is already sitting in a stranger's chat window, and the reader pressing it cannot tell
 * whether it has been opened, is being listened to right now, or was forwarded to four
 * people. So the dialog says what happens to THEM rather than what happens to the row.
 *
 * IT NAMES THE SUBJECT, because a list of links is a list of similar-looking rows and the
 * whole risk of a per-row delete button is revoking the neighbour of the one you meant.
 *
 * THE DELETE IS AN INERTIA VISIT rather than the `fetch` the MINT uses, and the asymmetry is
 * deliberate: minting wants a string back without disturbing the page, while revoking wants
 * exactly the opposite — the page re-rendered with one row fewer. `router.delete` does that,
 * and the flash rides back with it.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** The link being revoked — its id is the capability, and the URL this deletes. */
    id: string;
    /** What it grants, for the sentence that names it: an album title, an artist, a song. */
    name: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

/** Disables the button for the round trip, so a double press cannot send a second DELETE. */
const processing = ref(false);

/**
 * Revoke it, and let the page redraw itself.
 *
 * The modal closes on success rather than immediately: closing first would return the reader
 * to a list still showing the row, which then vanishes under them a moment later. It stays put
 * on failure too — a dialog that closed on an error would leave the toast explaining something
 * the reader can no longer see the subject of.
 */
function revoke(): void {
    processing.value = true;
    router.delete(`/shares/${props.id}`, {
        preserveScroll: true,
        onSuccess: () => emit("close"),
        onFinish: () => (processing.value = false)
    });
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ t("dashboard.shares.revoke.header") }}</template>

        <p>{{ t("dashboard.shares.revoke.body", { name }) }}</p>

        <template #footer>
            <Button variant="default" type="button" :disabled="processing" @click="revoke">
                <icon name="delete" :size="1" />
                <span>{{ t("dashboard.shares.revoke.confirm") }}</span>
            </Button>
        </template>
    </modal>
</template>
