<script setup lang="ts">
/******************************************************************************
 * TwoFactorDisabled
 * The "enable 2FA" form, shown when the user has no active two-factor auth
 * (ported from cantrip.me). A short explanation, then — when the feature's
 * confirmPassword option is on — a password field, then the enable button.
 * Submitting calls enableTwoFactor(), which confirms the password and posts to
 * Fortify's enable endpoint, opening the setup modal on success.
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";

const { t } = useI18n();

const { processing, validationErrors, requiresPasswordConfirmation, enableTwoFactor } = useTwoFactorAuth();

/** Password bound to the confirmation field (only shown when the session requires re-confirmation). */
const password = ref("");
/** Toggles the password field between `text` and `password` type for visibility. */
const showPassword = ref(false);

/** Legend notes — the intro is always shown; the password hint only when confirmation is required. */
const legendItems = computed(() => {
    const items = [{ slot: "intro", icon: "info" }];
    if (requiresPasswordConfirmation.value) items.push({ slot: "confirm", icon: "info" });
    return items;
});
</script>

<template>
    <form class="form" novalidate @submit.prevent="enableTwoFactor(password)">
        <form-legend :items="legendItems">
            <template #intro>
                <i18n-t keypath="dashboard.twoFactor.disabled.intro" scope="global">
                    <template #totp><strong>{{ t("dashboard.twoFactor.disabled.totpTerm") }}</strong></template>
                    <template #bitwarden><labelled-link href="https://bitwarden.com/">Bitwarden</labelled-link></template>
                    <template #enpass><labelled-link href="https://www.enpass.io/">Enpass</labelled-link></template>
                </i18n-t>
            </template>
            <template #confirm> {{ t("dashboard.twoFactor.disabled.confirm") }} </template>
        </form-legend>

        <form-row
            v-if="requiresPasswordConfirmation"
            for-id="password_enable_2fa"
            :label="t('dashboard.twoFactor.passwordLabel')"
            :error="validationErrors.password ?? ''"
            :invalid="!!validationErrors.password"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="password_enable_2fa"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
            />
            <template #button>
                <button
                    type="button"
                    tabindex="-1"
                    :aria-label="showPassword ? t('common.hidePassword') : t('common.showPassword')"
                    @mousedown.prevent
                    @click="showPassword = !showPassword"
                >
                    <icon :name="showPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPassword ? t("common.hide") : t("common.show") }}</span>
                </button>
            </template>
        </form-row>

        <form-row>
            <Button variant="primary" type="submit" :disabled="processing">
                <icon name="security" :size="1" />
                <span>{{ processing ? t("dashboard.twoFactor.disabled.submitting") : t("dashboard.twoFactor.disabled.submit") }}</span>
            </Button>
        </form-row>
    </form>
</template>
