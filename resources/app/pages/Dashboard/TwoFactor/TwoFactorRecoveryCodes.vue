<script setup lang="ts">
/******************************************************************************
 * TwoFactorRecoveryCodes
 * The recovery-codes panel inside TwoFactorEnabled (ported from cantrip.me).
 * One form with two submit buttons — "show" reveals the current codes, and
 * "regenerate" mints a fresh set — dispatched by the submitter's value. When a
 * password confirmation is required it is asked once before the codes are
 * revealed; the revealed codes sit in a readonly textarea for copying.
 *****************************************************************************/
import { computed, ref } from "vue";
import Button from "Components/Form/Button.vue";
import FormInput from "Components/Form/FormInput.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import FormRow from "Components/Form/FormRow.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";

const {
    handleRegenerateRecoveryCodes,
    handleShowRecoveryCodes,
    isRecoveryCodesVisible,
    processing,
    recoveryCodesList,
    requiresPasswordConfirmation,
    validationErrors
} = useTwoFactorAuth();

/** Recovery codes joined by newline for display in the readonly textarea. */
const recoveryCodesString = computed(() => recoveryCodesList.value.join("\n"));
/** Password bound to the confirmation field (only shown when the session requires re-confirmation). */
const password = ref("");
/** Toggles the password field between `text` and `password` type for visibility. */
const showPassword = ref(false);

/** Legend notes — intro always; the required-fields hint only while the password field is shown. */
const legendItems = computed(() => {
    const items = [{ slot: "intro", icon: "info" }];
    if (requiresPasswordConfirmation.value && !isRecoveryCodesVisible.value) items.push({ slot: "required", icon: "info" });
    return items;
});

/**
 * Dispatch the submission to the correct handler based on the submitter's value:
 * "show" reveals the existing codes, "regenerate" mints and shows a fresh set.
 *
 * @param e - The native submit event, used to identify which button submitted.
 */
const onSubmit = (e: SubmitEvent): void => {
    const action = (e.submitter as HTMLButtonElement | null)?.value;
    if (action === "show") handleShowRecoveryCodes(password.value);
    else if (action === "regenerate") handleRegenerateRecoveryCodes(password.value);
};
</script>

<template>
    <headline :size="4">Wiederherstellungscodes</headline>

    <form class="form" novalidate @submit.prevent="onSubmit">
        <form-legend :items="legendItems">
            <template #intro>
                Wiederherstellungscodes erlauben es dir, dich anzumelden, wenn du keinen Zugriff mehr auf dein
                2FA-Gerät hast. Speichere die Wiederherstellungscodes in einem sicheren Passwortmanager.
            </template>
            <template #required>
                Felder, die mit einem <icon name="required" /> gekennzeichnet sind, müssen ausgefüllt werden.
            </template>
        </form-legend>

        <form-row
            v-if="requiresPasswordConfirmation && !isRecoveryCodesVisible"
            for-id="recovery-codes-password"
            label="Passwort"
            :error="validationErrors.password ?? ''"
            :invalid="!!validationErrors.password"
            :required="true"
            addon-icon="key"
        >
            <form-input
                id="recovery-codes-password"
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

        <form-row v-if="!isRecoveryCodesVisible">
            <Button variant="default" type="submit" name="action" value="show" :disabled="processing">
                <icon name="visibility-on" :size="1" />
                <span>Wiederherstellungscodes anzeigen</span>
            </Button>
        </form-row>

        <template v-if="isRecoveryCodesVisible">
            <form-row label="Wiederherstellungscodes">
                <textarea class="recovery-codes" :value="recoveryCodesString" readonly rows="8" aria-readonly="true" />
            </form-row>

            <form-legend :items="[{ slot: 'intro', icon: 'info' }]">
                <template #intro>
                    Jeder Wiederherstellungscode kann nur einmal benutzt werden, um sich in deinem Benutzerkonto
                    anzumelden, und wird nach dem Benutzen entfernt. Wenn du mehr Wiederherstellungscodes benötigst,
                    benutze den Button „Neu erzeugen“.
                </template>
            </form-legend>

            <form-row>
                <Button variant="default" type="submit" name="action" value="regenerate" :disabled="processing">
                    <icon name="key" :size="1" />
                    <span>Neu erzeugen</span>
                </Button>
            </form-row>
        </template>
    </form>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

@layer components {
    // The revealed codes sit in a readonly textarea framed like a form-input
    // (tokens, not the global palette), in a monospace face so the codes align.
    .recovery-codes {
        width: 100%;
        padding: 0.75ex 2ch;
        border: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

        background-color: map.get(c.$c-input, "background");
        color: map.get(c.$c-input, "surface");
        border-radius: map.get(s.$c-input, "radius");

        font-family: monospace;
        line-height: 1.6;

        resize: vertical;
    }
}
</style>
