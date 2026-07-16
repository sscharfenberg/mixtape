<script setup lang="ts">
/******************************************************************************
 * ResetPasswordPage
 * The password-reset step (ported from cantrip.me's Auth/ResetPassword),
 * reached via the signed link from PasswordResetLinkNotification. `email` /
 * `token` come from NewPasswordController::show (already validated against
 * Fortify's password broker before this page renders), and ride along as
 * form fields so POST /reset-password can re-validate the token.
 *
 * Uses Inertia's <Form> with Precognition, matching RegisterPage, including
 * the live zxcvbn strength meter (usePasswordEntropy → PasswordStrength).
 *****************************************************************************/
import { Form, Head } from "@inertiajs/vue3";
import { ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import PasswordStrength from "Components/Form/PasswordStrength.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { usePasswordEntropy } from "Composables/usePasswordEntropy";

const props = defineProps<{
    /** The signed reset token from the emailed link; posted back as a hidden field. */
    token: string;
    /** The account email the link was issued for; shown read-only for confirmation. */
    email: string;
}>();

const inputEmail = ref(props.email);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

// Live, server-scored (zxcvbn) password strength for the meter.
const { password, score, onPasswordChange } = usePasswordEntropy();
</script>

<template>
    <Head>
        <title>Passwort zurücksetzen</title>
    </Head>
    <headline glow>
        <icon name="key" :size="3" />
        Passwort zurücksetzen
    </headline>

    <Form
        #default="{ errors, processing, validate, validating, valid, invalid }"
        action="/reset-password"
        method="post"
        class="form"
    >
        <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
            <template #intro>
                Gib dein neues Passwort ein. Nach dem Speichern kannst du dich mit dem neuen Passwort anmelden.
            </template>
        </form-legend>

        <form-row
            for-id="email"
            label="E-Mail"
            :error="errors.email ?? ''"
            :invalid="false"
            :validated="true"
            :validating="validating"
            addon-icon="mail"
            :required="true"
        >
            <form-input
                id="email"
                v-model="inputEmail"
                type="email"
                name="email"
                maxlength="255"
                readonly
                @change="validate('email')"
            />
        </form-row>

        <form-row
            for-id="password"
            label="Passwort"
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
            label="Passwort bestätigen"
            :error="errors.password_confirmation ?? ''"
            :invalid="invalid('password_confirmation')"
            :validated="valid('password_confirmation')"
            :validating="validating"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="password_confirmation"
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
                    <span>{{
                        showPasswordConfirmation ? "Verbergen" : "Anzeigen"
                    }}</span>
                </button>
            </template>
        </form-row>

        <input type="hidden" name="token" :value="token" />

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="save" :size="1" />
                <span>{{ processing ? "Wird gespeichert …" : "Speichern" }}</span>
            </Button>
        </form-row>
    </Form>
</template>
