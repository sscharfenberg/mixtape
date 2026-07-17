<script setup lang="ts">
/******************************************************************************
 * AccountDeleteModal
 * The password-confirmation modal for account deletion (ported from
 * cantrip.me's Dashboard/Delete/AccountDeleteModal). Submits via
 * useDeleteAccount, which posts DELETE /user/delete outside Inertia's normal
 * request cycle so a wrong password only updates this modal, not the page
 * behind it.
 *****************************************************************************/
import { onMounted, ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeleteAccount } from "Composables/useDeleteAccount";

const emit = defineEmits<{ close: [] }>();
const showPassword = ref(false);
const password = ref("");
const passwordRef = ref<InstanceType<typeof FormInput> | null>(null);
onMounted(() => passwordRef.value?.$el?.focus());

const { processing, passwordError, deleteAccount } = useDeleteAccount();

/** Submit the current password to the delete-account endpoint. */
function onSubmit(): void {
    deleteAccount(password.value);
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>Bestätige die Löschung</template>

        <form id="account-delete-form" class="form" @submit.prevent="onSubmit">
            <form-legend :items="[{ slot: 'intro', icon: 'question' }]">
                <template #intro>Bist du sicher, dass du dein Benutzerkonto permanent löschen willst?</template>
            </form-legend>

            <form-row
                for-id="delete-password"
                label="Passwort"
                :required="true"
                addon-icon="key"
                :error="passwordError"
                :invalid="!!passwordError"
            >
                <form-input
                    ref="passwordRef"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    id="delete-password"
                    autocomplete="current-password"
                />
                <template #button>
                    <button
                        type="button"
                        tabindex="-1"
                        :aria-label="showPassword ? 'Passwort verbergen' : 'Passwort anzeigen'"
                        @mousedown.prevent
                        @click="showPassword = !showPassword"
                    >
                        <icon :name="showPassword ? 'visibility-off' : 'visibility-on'" />
                        <span>{{ showPassword ? "Verbergen" : "Anzeigen" }}</span>
                    </button>
                </template>
            </form-row>
        </form>

        <template #footer>
            <Button variant="primary" type="submit" form="account-delete-form" :disabled="processing || !password">
                <icon name="delete" :size="1" />
                <span>Löschen</span>
            </Button>
        </template>
    </modal>
</template>
