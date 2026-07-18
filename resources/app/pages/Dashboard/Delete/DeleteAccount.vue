<script setup lang="ts">
/******************************************************************************
 * DeleteAccount
 * The dashboard's "delete account" section (ported from cantrip.me's
 * Dashboard/Delete/DeleteAccount). Opens AccountDeleteModal for the actual
 * password-confirmed DELETE /user/delete — this component is just the
 * explanation + the button that reveals the modal.
 *****************************************************************************/
import { ref } from "vue";
import { useI18n } from "vue-i18n";
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

const { t } = useI18n();

const showModal = ref(false);
</script>

<template>
    <headline :size="3" anchor-id="deleteSection" glow :align="align">
        <icon name="delete" />
        {{ t("dashboard.delete.headline") }}
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
                <i18n-t keypath="dashboard.delete.explanation" scope="global">
                    <template #warning><strong>{{ t("dashboard.delete.warning") }}</strong></template>
                </i18n-t>
            </template>
            <template #no_soft_deletes>
                {{ t("dashboard.delete.noSoftDeletes") }}
            </template>
            <template #reversed>
                {{ t("dashboard.delete.reversed") }}
            </template>
        </form-legend>

        <form-row>
            <Button variant="primary" type="submit">
                <icon name="delete" :size="1" />
                <span>{{ t("dashboard.delete.headline") }}</span>
            </Button>
        </form-row>
    </form>

    <account-delete-modal v-if="showModal" @close="showModal = false" />
</template>
