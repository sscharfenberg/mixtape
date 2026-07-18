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
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";
import TwoFactorRecoveryCodes from "./TwoFactorRecoveryCodes.vue";

withDefaults(
    defineProps<{
        /** Which edge the section's glowing-border headline tabs hug (threaded down from TwoFactor). */
        align?: "left" | "right";
    }>(),
    {
        align: "left"
    }
);

const { t } = useI18n();

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
    <two-factor-recovery-codes :align="align" />

    <headline :size="4" glow :align="align">{{ t("dashboard.twoFactor.enabled.headline") }}</headline>

    <form class="form" novalidate @submit.prevent="disableTwoFactor(password)">
        <form-legend :items="legendItems">
            <template #intro>
                {{ t("dashboard.twoFactor.enabled.intro") }}
            </template>
            <template #required>
                <i18n-t keypath="common.requiredFieldsHint" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>

        <form-row
            v-if="requiresPasswordConfirmation"
            for-id="disable-password"
            :label="t('dashboard.twoFactor.passwordLabel')"
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
                <span>{{ processing ? t("dashboard.twoFactor.enabled.submitting") : t("dashboard.twoFactor.enabled.submit") }}</span>
            </Button>
        </form-row>
    </form>
</template>
