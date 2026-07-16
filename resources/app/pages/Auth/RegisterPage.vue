<script setup lang="ts">
/******************************************************************************
 * RegisterPage
 * Invite-only account creation via Fortify, with live validation and a password
 * strength meter. Reachable only through a valid invite link
 * (AuthController::registerView bounces bad/expired codes to /login first).
 *
 * Uses Inertia's <Form> with Precognition: each field validates server-side on
 * blur (@change → validate(field)), driven by CreateNewUser's rules (incl. the
 * zxcvbn PasswordEntropy gate). The password field additionally feeds a live
 * strength meter (usePasswordEntropy → /password/entropy → PasswordStrength).
 * On success Fortify creates the (unverified) user and RegisterResponse sends
 * them to the landing page with a "check your email" toast. The invite `code`
 * rides along as a hidden field.
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

defineProps<{
    /** Invite code from the registration link; posted back as a hidden field so
     *  the backend can re-validate and consume the one-time invite. */
    code: string;
}>();

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

// Live, server-scored (zxcvbn) password strength for the meter.
const { password, score, onPasswordChange } = usePasswordEntropy();
</script>

<template>
    <Head>
        <title>Registrieren</title>
    </Head>
    <headline glow>
        <icon name="register" :size="3" />
        Registrierung
    </headline>

    <Form
        #default="{ errors, processing, validate, validating, valid, invalid }"
        action="/register"
        method="post"
        class="form"
    >
        <form-legend
            :items="[
                { slot: 'intro', icon: 'info', modifier: 'warning' },
                { slot: 'required', icon: 'info' },
                { slot: 'password', icon: 'key' }
            ]"
        >
            <template #intro>
                Nach der Registrierung schicken wir dir einen Link zur Bestätigung der E-Mail-Adresse. Du kannst dich
                erst einloggen, wenn die E-Mail-Adresse bestätigt wurde.
            </template>
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
            <template #password>
                Während der Eingabe des Passwortes prüfen wir, ob das Passwort sicher genug ist. Unsichere Passwörter
                werden abgewiesen.
            </template>
        </form-legend>

        <form-row
            for-id="name"
            label="Benutzername"
            :error="errors.name ?? ''"
            :invalid="invalid('name')"
            :validated="valid('name')"
            :validating="validating"
            addon-icon="account"
            :required="true"
        >
            <form-input
                id="name"
                type="text"
                name="name"
                autocomplete="username"
                maxlength="80"
                autofocus
                @change="validate('name')"
            />
        </form-row>

        <form-row
            for-id="email"
            label="E-Mail"
            :error="errors.email ?? ''"
            :invalid="invalid('email')"
            :validated="valid('email')"
            :validating="validating"
            addon-icon="mail"
            :required="true"
        >
            <form-input
                id="email"
                type="email"
                name="email"
                autocomplete="email"
                maxlength="255"
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
            addon-icon="key"
            :required="true"
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
            <!-- meter lives in the #text slot so it aligns to the input column width -->
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
            addon-icon="key"
            :required="true"
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
                    <span>{{ showPasswordConfirmation ? "Verbergen" : "Anzeigen" }}</span>
                </button>
            </template>
        </form-row>

        <input type="hidden" name="code" :value="code" />

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="register" :size="1" />
                <span>{{ processing ? "Wird registriert …" : "Registrieren" }}</span>
            </Button>
        </form-row>
    </Form>
</template>
