<script setup lang="ts">
/******************************************************************************
 * LoginPage
 * Session login via Fortify, with the two-factor challenge kept on this same
 * page. useLogin() submits name + password (+ remember) to POST /login as JSON,
 * so Fortify answers `{ two_factor: true }` for a 2FA-enabled user instead of
 * redirecting — the form then swaps the credential fields for a code field
 * (a 6-digit OTP, or a recovery code toggled via radio) and posts the challenge
 * to /two-factor-challenge. A user without 2FA is navigated straight to the
 * dashboard. Validation / failed-credential errors come back on the `name`
 * field (Fortify::username() === 'name'). Registration is invite-only (no link
 * here); the forgot-password and resend-verification links are each gated on
 * their own feature flag.
 *
 * Intentionally style-free: it composes the shared components, so there are no
 * page-specific styles or tokens here.
 *****************************************************************************/
import { Head, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import Button from "Components/Form/Button.vue";
import Checkbox from "Components/Form/Checkbox.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import OTPInput from "Components/Form/OTPInput/OTPInput.vue";
import RadioButtonGroup from "Components/Form/Radio/RadioButtonGroup.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import LinkGroup from "Components/UI/LinkGroup.vue";
import { useLogin } from "Composables/useLogin";

defineProps<{
    /** Optional session status message (e.g. after a password reset). */
    status?: string;
}>();

const page = usePage();
/** Backend feature flags — gate the guest-only recovery links. */
const features = computed(() => page.props.features);
const showPassword = ref(false);

const { errors, name, password, remember, requiresTwoFactor, recoveryCode, showRecoveryCode, processing, submit } =
    useLogin();

/** Legend notes — the required-fields hint always; the 2FA prompt once challenged. */
const legendItems = computed(() => {
    const items = [{ slot: "required", icon: "info" }];
    if (requiresTwoFactor.value) items.push({ slot: "twoFactor", icon: "security" });
    return items;
});

/** The code-type toggle shown during the challenge (TOTP vs. recovery code). */
const codeTypes = computed(() => [
    { value: "2fa", label: "Einmalkennwort verwenden", checked: !showRecoveryCode.value },
    { value: "recovery", label: "Wiederherstellungscode verwenden", checked: showRecoveryCode.value }
]);

/** Flip between the 6-digit TOTP field and the free-text recovery-code field. */
const onCodeTypeChange = (event: Event): void => {
    const value = (event.target as HTMLInputElement | null)?.value;
    showRecoveryCode.value = value === "recovery";
    recoveryCode.value = "";
};

/** Submit-button label, reflecting the stage (login vs. challenge) and progress. */
const submitLabel = computed(() => {
    if (processing.value) return requiresTwoFactor.value ? "Wird überprüft …" : "Wird angemeldet …";
    return requiresTwoFactor.value ? "Überprüfen" : "Anmelden";
});
</script>

<template>
    <Head>
        <title>Anmelden</title>
    </Head>
    <headline glow>
        <icon name="key" :size="3" />
        Anmeldung
    </headline>

    <p v-if="status" role="status">{{ status }}</p>

    <form class="form" novalidate @submit.prevent="submit">
        <form-legend :items="legendItems">
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
            <template #twoFactor>
                Für dein Benutzerkonto ist die Zwei-Faktor Authentifizierung aktiviert. Bitte prüfe deine App und gib
                das Einmalkennwort ein, das angezeigt wird.
            </template>
        </form-legend>

        <template v-if="!requiresTwoFactor">
            <form-row
                for-id="name"
                label="Benutzername"
                :error="errors.name ?? ''"
                :invalid="!!errors.name"
                addon-icon="register"
                :required="true"
            >
                <form-input id="name" v-model="name" type="text" name="name" autocomplete="username" autofocus />
            </form-row>

            <form-row
                for-id="password"
                label="Passwort"
                :error="errors.password ?? ''"
                :invalid="!!errors.password"
                addon-icon="key"
                :required="true"
            >
                <form-input
                    id="password"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
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

            <form-row for-id="remember" label="Angemeldet bleiben">
                <checkbox ref-id="remember" v-model="remember" />
            </form-row>
        </template>

        <template v-else>
            <form-row
                for-id="code"
                :label="showRecoveryCode ? 'Wiederherstellungscode' : 'Einmalkennwort'"
                :error="showRecoveryCode ? (errors.recovery_code ?? '') : (errors.code ?? '')"
                :invalid="showRecoveryCode ? !!errors.recovery_code : !!errors.code"
                :required="true"
                :addon-icon="showRecoveryCode ? 'key' : undefined"
            >
                <OTPInput
                    v-if="!showRecoveryCode"
                    id="code"
                    v-model="recoveryCode"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="6"
                    autofocus
                    @complete="submit"
                />
                <form-input
                    v-else
                    id="code"
                    v-model="recoveryCode"
                    type="text"
                    name="code"
                    autocomplete="one-time-code"
                    autofocus
                />
            </form-row>

            <form-row>
                <radio-button-group name="type" :radio-buttons="codeTypes" layout="row" @change="onCodeTypeChange" />
            </form-row>
        </template>

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="login" :size="1" />
                <span>{{ submitLabel }}</span>
            </Button>
        </form-row>

        <form-row v-if="features.resetPasswords || features.emailVerification" style="margin-top: 2rem">
            <link-group label="Wenn du dich nicht anmelden kannst, verwende diese Links.">
                <labelled-link v-if="features.resetPasswords" href="/forgot">Probleme beim Anmelden?</labelled-link>
                <labelled-link v-if="features.emailVerification" href="/resend-verification">
                    Bestätigungs-Link erneut versenden
                </labelled-link>
            </link-group>
        </form-row>
    </form>
</template>
