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
import { useI18n } from "vue-i18n";
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

const { t } = useI18n();

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
        {{ t("dashboard.password.headline") }}
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
                {{ t("dashboard.password.intro") }}
            </template>
            <template #required>
                <i18n-t keypath="common.requiredFieldsHint" scope="global">
                    <template #icon><icon name="required" /></template>
                </i18n-t>
            </template>
        </form-legend>

        <form-row
            for-id="current_password"
            :label="t('dashboard.password.currentLabel')"
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
                    :aria-label="showCurrentPassword ? t('common.hidePassword') : t('common.showPassword')"
                    @mousedown.prevent
                    @click="showCurrentPassword = !showCurrentPassword"
                >
                    <icon :name="showCurrentPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showCurrentPassword ? t("common.hide") : t("common.show") }}</span>
                </button>
            </template>
        </form-row>

        <form-row
            for-id="password"
            :label="t('dashboard.password.newLabel')"
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
                    :aria-label="showPassword ? t('common.hidePassword') : t('common.showPassword')"
                    @mousedown.prevent
                    @click="showPassword = !showPassword"
                >
                    <icon :name="showPassword ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPassword ? t("common.hide") : t("common.show") }}</span>
                </button>
            </template>
            <template v-if="score !== null" #text>
                <password-strength :score="score" />
            </template>
        </form-row>

        <form-row
            for-id="password_confirmation"
            :label="t('dashboard.password.confirmLabel')"
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
                    :aria-label="showPasswordConfirmation ? t('common.hidePassword') : t('common.showPassword')"
                    @mousedown.prevent
                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                >
                    <icon :name="showPasswordConfirmation ? 'visibility-off' : 'visibility-on'" />
                    <span>{{ showPasswordConfirmation ? t("common.hide") : t("common.show") }}</span>
                </button>
            </template>
        </form-row>

        <form-row>
            <Button variant="default" type="submit" :disabled="processing">
                <icon name="save" :size="1" />
                <span>{{ processing ? t("dashboard.password.submitting") : t("dashboard.password.submit") }}</span>
            </Button>
        </form-row>
    </Form>
</template>
