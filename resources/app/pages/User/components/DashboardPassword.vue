<script setup lang="ts">
/******************************************************************************
 * DashboardPassword
 * The dashboard's "change password" section (ported from cantrip.me's
 * Dashboard/DashboardPassword). PUT /user/password (Fortify's own
 * PasswordController, deferring to App\Actions\Fortify\UpdateUserPassword) —
 * requires the current password plus a new one meeting the same zxcvbn
 * entropy gate as registration.
 *****************************************************************************/
import { Form } from "@inertiajs/vue3";
import { ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import PasswordStrength from "Components/Form/PasswordStrength.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { usePasswordEntropy } from "Composables/usePasswordEntropy";

withDefaults(
    defineProps<{
        /** Which edge the headline's glowing-border tab hugs. */
        align?: "left" | "right";
    }>(),
    {
        align: "left"
    }
);

const { password, score, onPasswordChange, reset } = usePasswordEntropy();
const currentPassword = ref("");
const passwordConfirmation = ref("");
const showCurrentPassword = ref(false);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

/**
 * Clear every field after a successful change. `resetOnSuccess`'s own
 * DOM-level reset only covers fields Inertia's <Form> can see as "changed"
 * from their mount-time default — since current_password/password_confirmation
 * aren't otherwise Vue-controlled, v-model here guarantees a reliable clear
 * regardless of that mechanism.
 */
function resetForm(): void {
    reset();
    currentPassword.value = "";
    passwordConfirmation.value = "";
}
</script>

<template>
    <headline :size="3" anchor-id="passwordSection" glow :align="align">
        <icon name="key" />
        Passwort ändern
    </headline>

    <Form
        action="/user/password"
        method="put"
        class="form"
        reset-on-success
        @success="resetForm"
        #default="{ errors, valid, invalid, validating, validate, processing }"
    >
        <form-legend
            :items="[
                { slot: 'intro', icon: 'info' },
                { slot: 'required', icon: 'info' }
            ]"
        >
            <template #intro>
                Du kannst dein Passwort ändern, indem du dein aktuelles Passwort und das neue Passwort eingibst.
            </template>
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
        </form-legend>

        <form-row
            for-id="current_password"
            label="Aktuelles Passwort"
            :error="errors.current_password ?? ''"
            :invalid="invalid('current_password')"
            :validated="valid('current_password')"
            :validating="validating"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="current_password"
                v-model="currentPassword"
                :type="showCurrentPassword ? 'text' : 'password'"
                name="current_password"
                autocomplete="current-password"
                @change="validate('current_password')"
            />
            <template #button>
                <button
                    type="button"
                    tabindex="-1"
                    :aria-label="showCurrentPassword ? 'Passwort verbergen' : 'Passwort anzeigen'"
                    @mousedown.prevent
                    @click="showCurrentPassword = !showCurrentPassword"
                >
                    <icon :name="showCurrentPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showCurrentPassword ? "Verbergen" : "Anzeigen" }}</span>
                </button>
            </template>
        </form-row>

        <form-row
            for-id="password"
            label="Neues Passwort"
            :error="errors.password ?? ''"
            :invalid="invalid('password')"
            :validated="valid('password')"
            :validating="validating"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="new-password"
                @change="validate('password')"
                @keyup="onPasswordChange"
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
            <template v-if="score !== null" #text>
                <password-strength :score="score" />
            </template>
        </form-row>

        <form-row
            for-id="password_confirmation"
            label="Neues Passwort bestätigen"
            :error="errors.password_confirmation ?? ''"
            :invalid="invalid('password_confirmation')"
            :validated="valid('password_confirmation')"
            :validating="validating"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="password_confirmation"
                v-model="passwordConfirmation"
                :type="showPasswordConfirmation ? 'text' : 'password'"
                name="password_confirmation"
                autocomplete="new-password"
                @change="validate('password_confirmation')"
            />
            <template #button>
                <button
                    type="button"
                    tabindex="-1"
                    :aria-label="showPasswordConfirmation ? 'Passwort verbergen' : 'Passwort anzeigen'"
                    @mousedown.prevent
                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                >
                    <icon :name="showPasswordConfirmation ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPasswordConfirmation ? "Verbergen" : "Anzeigen" }}</span>
                </button>
            </template>
        </form-row>

        <form-row>
            <Button variant="default" type="submit" :disabled="processing">
                <icon name="save" :size="1" />
                <span>{{ processing ? "Wird geändert …" : "Ändern" }}</span>
            </Button>
        </form-row>
    </Form>
</template>
