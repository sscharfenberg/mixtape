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
import { Head, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import StickyNav from "Components/UI/StickyNav.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import DashboardExportPresets from "./DashboardExportPresets.vue";
import DashboardPassword from "./DashboardPassword.vue";
import DashboardProfile from "./DashboardProfile.vue";
import DashboardShares from "./DashboardShares.vue";
import DeleteAccount from "./Delete/DeleteAccount.vue";
import TwoFactor from "./TwoFactor/TwoFactor.vue";

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "dashboard.page.title", icon: "user-settings" }]);

/**
 * Whether this reader has shared anything — which decides both the section at the top and its
 * jump-link below.
 *
 * A shared prop rather than one of this page's own, because the header's user menu needs the
 * same answer from every page in the app (HandleInertiaRequests explains the shape).
 */
const hasShares = computed<boolean>(() => usePage().props.hasShares === true);

/**
 * StickyNav jump-links, one per section below (labels localised).
 *
 * The shares link is conditional for the same reason the section is, and dropping it from the
 * ARRAY rather than hiding it in the nav is what keeps the two honest: a jump-link to an
 * anchor that is not on the page scrolls nowhere and reads as a broken control.
 */
const navItems = computed(() => [
    ...(hasShares.value ? [{ id: "sharesSection", label: t("dashboard.page.nav.shares") }] : []),
    { id: "presetsSection", label: t("dashboard.page.nav.presets") },
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

    <!-- Only for a reader who has shared something. It is not a setting — it is a thing they
         made that somebody else is holding — so it sits above the ones that are, and is absent
         entirely for the accounts that have never pressed "share".

         IT STAYS THE ONLY CONDITIONAL SECTION, AND IT STAYS FIRST, which is what makes the
         alternation below work at all: `left` here, and the fixed run starting at `right`, so
         the sides hold whether or not this is drawn. A section that came and went from the
         MIDDLE of the run would flip every tab beneath it as it appeared. -->
    <dashboard-shares v-if="hasShares" align="left" />

    <!-- FIRST OF THE SETTINGS, and for most readers the first section on the page — the shares
         block above is absent unless they have sent a link. It leads because it is the only one
         here a reader visits to get something DONE (an export that will play in the car) rather
         than to administer the account; password, profile and 2FA are things you set once and
         come back to rarely, and deleting is last for the obvious reason. -->
    <dashboard-export-presets align="right" />
    <dashboard-password align="left" />
    <dashboard-profile align="right" />
    <two-factor align="left" />
    <delete-account align="right" />
</template>
