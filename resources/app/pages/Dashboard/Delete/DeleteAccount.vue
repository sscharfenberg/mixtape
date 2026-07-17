<script setup lang="ts">
/******************************************************************************
 * DeleteAccount
 * The dashboard's "delete account" section (ported from cantrip.me's
 * Dashboard/Delete/DeleteAccount). Opens AccountDeleteModal for the actual
 * password-confirmed DELETE /user/delete — this component is just the
 * explanation + the button that reveals the modal.
 *****************************************************************************/
import { ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import AccountDeleteModal from "./AccountDeleteModal.vue";

withDefaults(
    defineProps<{
        /** Which edge the headline's glowing-border tab hugs. */
        align?: "left" | "right";
    }>(),
    {
        align: "left"
    }
);

const showModal = ref(false);
</script>

<template>
    <headline :size="3" anchor-id="deleteSection" glow :align="align">
        <icon name="delete" />
        Benutzerkonto löschen
    </headline>

    <form class="form" @submit.prevent="showModal = true">
        <form-legend
            :items="[
                { slot: 'explanation', icon: 'warning', modifier: 'warning' },
                { slot: 'no_soft_deletes', icon: 'info' },
                { slot: 'reversed', icon: 'error', modifier: 'error' }
            ]"
        >
            <template #explanation>
                Wenn nötig kannst du hier dein Benutzerkonto löschen. <strong>Achtung</strong> — diese Aktion löscht
                dein Benutzerkonto und alle damit verbundenen Daten.
            </template>
            <template #no_soft_deletes>
                Wir verwenden keine Markierung als „gelöscht“ — das Benutzerkonto wird sofort und dauerhaft entfernt.
            </template>
            <template #reversed>
                Diese Aktion kann nicht rückgängig gemacht werden — wir können gelöschte Benutzerkonten nicht
                wiederherstellen.
            </template>
        </form-legend>

        <form-row>
            <Button variant="primary" type="submit">
                <icon name="delete" :size="1" />
                <span>Benutzerkonto löschen</span>
            </Button>
        </form-row>
    </form>

    <account-delete-modal v-if="showModal" @close="showModal = false" />
</template>
