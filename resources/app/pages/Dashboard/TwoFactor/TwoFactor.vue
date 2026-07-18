<script setup lang="ts">
/******************************************************************************
 * TwoFactor
 * The dashboard's two-factor-authentication section (ported from cantrip.me).
 * A status badge in the headline's #right slot reflects enabled/disabled, and
 * the body swaps between the enable form (TwoFactorDisabled) and the disable +
 * recovery-codes forms (TwoFactorEnabled). The setup modal lives here rather
 * than inside TwoFactorDisabled so it survives the Disabled → Enabled swap that
 * happens the moment Fortify flips `twoFactorEnabled` to true after enrollment.
 * Reads its control flags from the composable (fed by DashboardController props).
 *****************************************************************************/
import Badge from "Components/UI/Badge.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";
import TwoFactorDisabled from "./TwoFactorDisabled.vue";
import TwoFactorEnabled from "./TwoFactorEnabled.vue";
import TwoFactorModal from "./TwoFactorModal.vue";

withDefaults(
    defineProps<{
        /** Which edge the headline's glowing-border tab hugs. */
        align?: "left" | "right";
    }>(),
    {
        align: "left"
    }
);

const { twoFactorEnabled, requiresConfirmation, showSetupModal, clearSetupData } = useTwoFactorAuth();

/**
 * Close the setup modal and reset transient setup data. Lives on the parent so
 * the modal survives the TwoFactorDisabled → TwoFactorEnabled swap that happens
 * as soon as Fortify flips `twoFactorEnabled` to true.
 */
const handleModalClose = (): void => {
    showSetupModal.value = false;
    clearSetupData();
};
</script>

<template>
    <headline :size="3" anchor-id="twoFactorSection" glow :align="align">
        <icon name="security" />
        Zwei-Faktor Authentifizierung
        <template #right>
            <badge v-if="!twoFactorEnabled" type="warning"><icon name="key" :size="1" />Deaktiviert</badge>
            <badge v-else type="success"><icon name="security" />Aktiviert</badge>
        </template>
    </headline>

    <TwoFactorDisabled v-if="!twoFactorEnabled" />
    <TwoFactorEnabled v-else :align="align" />

    <TwoFactorModal v-if="showSetupModal" :requires-confirmation="requiresConfirmation" @close="handleModalClose" />
</template>

<style scoped lang="scss">
@layer components {
    // The badge is slot content we pass into Headline's #right slot, so it keeps
    // this component's scope id — a plain (non-:deep) selector matches it. It
    // inherits the h3 font-size (25.6px), which makes it enormous; shrink it back
    // to a proportional size.
    .badge {
        font-size: 0.8rem;
    }
}
</style>
