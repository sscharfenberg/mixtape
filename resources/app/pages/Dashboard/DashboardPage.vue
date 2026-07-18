<script setup lang="ts">
/******************************************************************************
 * DashboardPage
 * The authenticated user's landing page — Fortify redirects here after login
 * (config/fortify.php → 'home' => '/dashboard'; route named 'dashboard', behind
 * the `auth` middleware). Ported from cantrip.me's Dashboard/Dashboard: a
 * StickyNav jump-nav plus one section per settings area (password, profile,
 * two-factor auth, account deletion). Card-game-only sections (deck view/sort,
 * collection integration) have no MixTape equivalent yet.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import StickyNav from "Components/UI/StickyNav.vue";
import DashboardPassword from "./DashboardPassword.vue";
import DashboardProfile from "./DashboardProfile.vue";
import DeleteAccount from "./Delete/DeleteAccount.vue";
import TwoFactor from "./TwoFactor/TwoFactor.vue";

const { t } = useI18n();

const navItems = computed(() => [
    { id: "passwordSection", label: t("dashboard.page.nav.password") },
    { id: "profileSection", label: t("dashboard.page.nav.profile") },
    { id: "twoFactorSection", label: t("dashboard.page.nav.twoFactor") },
    { id: "deleteSection", label: t("dashboard.page.nav.delete") }
]);
</script>

<template>
    <Head :title="t('dashboard.page.title')" />
    <headline glow align="left">
        <icon name="user-settings" :size="3" />
        {{ t("dashboard.page.title") }}
    </headline>

    <sticky-nav :items="navItems" />

    <dashboard-password align="right" />
    <dashboard-profile align="left" />
    <two-factor align="right" />
    <delete-account align="left" />
</template>
