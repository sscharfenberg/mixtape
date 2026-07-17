<script setup lang="ts">
/******************************************************************************
 * TwoFactorEnabled
 * Shown when two-factor auth is active (ported from cantrip.me): the recovery
 * codes panel (TwoFactorRecoveryCodes) plus the "disable 2FA" form. Submitting
 * calls disableTwoFactor(), which confirms the password and DELETEs Fortify's
 * 2FA endpoint; the dashboard reloads with `twoFactorEnabled` false and the
 * section swaps back to TwoFactorDisabled.
 *****************************************************************************/
import { computed, ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";
import TwoFactorRecoveryCodes from "./TwoFactorRecoveryCodes.vue";

const { processing, validationErrors, requiresPasswordConfirmation, disableTwoFactor } = useTwoFactorAuth();

const password = ref("");
const showPassword = ref(false);

/** Legend notes — the intro is always shown; the required-fields hint only when a password is needed. */
const legendItems = computed(() => {
    const items = [{ slot: "intro", icon: "info" }];
    if (requiresPasswordConfirmation.value) items.push({ slot: "required", icon: "info" });
    return items;
});
</script>

<template>
    <two-factor-recovery-codes />

    <headline :size="4">Zwei-Faktor Authentifizierung deaktivieren</headline>

    <form class="form" novalidate @submit.prevent="disableTwoFactor(password)">
        <form-legend :items="legendItems">
            <template #intro>
                Gib dein Passwort ein, um die Zwei-Faktor Authentifizierung zu deaktivieren.
            </template>
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
        </form-legend>

        <form-row
            v-if="requiresPasswordConfirmation"
            for-id="disable-password"
            label="Passwort"
            :error="validationErrors.password ?? ''"
            :invalid="!!validationErrors.password"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="disable-password"
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

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="security" :size="1" />
                <span>{{ processing ? "Wird deaktiviert …" : "2FA deaktivieren" }}</span>
            </Button>
        </form-row>
    </form>
</template>
