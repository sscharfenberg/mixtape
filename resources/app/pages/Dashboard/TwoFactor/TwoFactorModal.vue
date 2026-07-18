<script setup lang="ts">
/******************************************************************************
 * TwoFactorModal
 * The 2FA enrollment modal (ported from cantrip.me). Step 1 shows the QR code
 * and the manual setup key (fetched on mount) so the user can add the account
 * to their authenticator app. When the feature's confirm option is on, step 2
 * asks for a TOTP code and posts it to Fortify's confirmed-2FA endpoint; on
 * success the modal closes and the dashboard reloads as enabled. Without
 * confirmation, "Next" just closes (2FA is already active).
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { nextTick, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import OTPInput from "Components/Form/OTPInput/OTPInput.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import { useClipboard } from "Composables/useClipboard";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";

const props = withDefaults(
    defineProps<{
        /** When true, an OTP verification step is required after scanning the QR code. */
        requiresConfirmation?: boolean;
    }>(),
    {
        requiresConfirmation: false
    }
);

/** @emits close — Fired when the modal should be dismissed (cancellation or successful confirmation). */
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

const { qrCodeSvg, manualSetupKey, fetchSetupData } = useTwoFactorAuth();
const { copy, copied } = useClipboard();

/** Which step is visible: `false` = QR code / setup key, `true` = OTP verification. */
const showVerificationStep = ref(false);
/** The OTP code entered during the verification step. */
const code = ref("");
/** True while the verification POST is in flight. */
const processing = ref(false);
/** Server-side validation error for the OTP code field. */
const codeError = ref<string | undefined>(undefined);

/** Load the QR code + manual setup key if they aren't already in shared state. */
onMounted(async () => {
    if (!qrCodeSvg.value) {
        await fetchSetupData();
    }
});

/**
 * Handle the "next step" button. When confirmation is required, reveal the OTP
 * step and focus the input; otherwise close the modal (2FA is already active).
 */
const handleModalNextStep = async (): Promise<void> => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;
        await nextTick();
        document.querySelector<HTMLInputElement>("[data-input-otp]")?.focus();
        return;
    }
    emit("close");
};

/**
 * Submit the OTP code to confirm 2FA. Posted via the Inertia router so its
 * validation errors arrive in the `confirmTwoFactorAuthentication` error bag
 * (redirect + session errors); the field resets on failure, modal closes on
 * success.
 */
const submitVerification = (): void => {
    processing.value = true;
    codeError.value = undefined;
    router.post(
        "/user/confirmed-two-factor-authentication",
        { code: code.value },
        {
            errorBag: "confirmTwoFactorAuthentication",
            onSuccess: () => emit("close"),
            onError: errors => {
                codeError.value = errors.code;
                code.value = "";
            },
            onFinish: () => {
                processing.value = false;
            }
        }
    );
};
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <span v-if="!showVerificationStep">{{ t("dashboard.twoFactor.modal.headerSetup") }}</span>
            <span v-else>{{ t("dashboard.twoFactor.modal.headerConfirm") }}</span>
        </template>

        <div v-if="!showVerificationStep" class="form">
            <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
                <template #intro>
                    {{ t("dashboard.twoFactor.modal.introSetup") }}
                </template>
            </form-legend>

            <loading-spinner v-if="!qrCodeSvg" :size="2" />
            <form-row v-else :label="t('dashboard.twoFactor.modal.qrCodeLabel')">
                <!-- eslint-disable-next-line vue/no-v-html — trusted server-rendered SVG from Fortify -->
                <div class="qr-code" v-html="qrCodeSvg" />
            </form-row>

            <loading-spinner v-if="!manualSetupKey" :size="2" />
            <form-row v-else for-id="manualSetupKey" :label="t('dashboard.twoFactor.modal.manualKeyLabel')" addon-icon="key">
                <form-input id="manualSetupKey" :model-value="manualSetupKey ?? ''" name="manualSetupKey" readonly />
                <template #button>
                    <button type="button" @click="copy(manualSetupKey ?? '')">
                        <icon :name="copied ? 'check' : 'copy'" />
                        <span>{{ copied ? t("dashboard.twoFactor.modal.copied") : t("dashboard.twoFactor.modal.copy") }}</span>
                    </button>
                </template>
            </form-row>
        </div>

        <form v-else id="two-factor-verify-form" class="form" novalidate @submit.prevent="submitVerification">
            <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
                <template #intro>
                    {{ t("dashboard.twoFactor.modal.introVerify") }}
                </template>
            </form-legend>

            <form-row
                for-id="code"
                :label="t('dashboard.twoFactor.modal.codeLabel')"
                :error="codeError ?? ''"
                :invalid="!!codeError"
                :required="true"
            >
                <OTPInput
                    id="code"
                    v-model="code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="6"
                    autofocus
                    @complete="submitVerification"
                />
            </form-row>
        </form>

        <template #footer>
            <template v-if="!showVerificationStep">
                <Button variant="primary" type="button" @click="handleModalNextStep">
                    <span>{{ t("dashboard.twoFactor.modal.nextStep") }}</span>
                </Button>
            </template>
            <template v-else>
                <Button variant="default" type="button" :disabled="processing" @click="showVerificationStep = false">
                    <span>{{ t("dashboard.twoFactor.modal.back") }}</span>
                </Button>
                <Button
                    variant="primary"
                    type="submit"
                    form="two-factor-verify-form"
                    :disabled="processing || code.length < 6"
                >
                    <icon name="check" :size="1" />
                    <span>{{ processing ? t("dashboard.twoFactor.modal.confirming") : t("dashboard.twoFactor.modal.confirm") }}</span>
                </Button>
            </template>
        </template>
    </modal>
</template>

<style scoped lang="scss">
.qr-code {
    display: flex;
    justify-content: center;

    // Fortify renders the QR as an inline SVG; cap its size and let it be fluid.
    :deep(svg) {
        width: 12rem;
        max-width: 100%;
        height: auto;
    }
}
</style>
