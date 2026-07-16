<script setup lang="ts">
/******************************************************************************
 * DashboardPage
 * The authenticated user's landing page — Fortify redirects here after login
 * (config/fortify.php → 'home' => '/dashboard'; route named 'dashboard', behind
 * the `auth` middleware). Ported from cantrip.me's Dashboard/Dashboard: a
 * StickyNav jump-nav plus one section per settings area. Card-game-only
 * sections (deck view/sort, collection integration) and 2FA management have
 * no MixTape equivalent yet / land separately.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import StickyNav from "Components/UI/StickyNav.vue";
import DashboardPassword from "./components/DashboardPassword.vue";
import DashboardProfile from "./components/DashboardProfile.vue";
import DeleteAccount from "./components/Delete/DeleteAccount.vue";

const navItems = [
    { id: "passwordSection", label: "Passwort" },
    { id: "profileSection", label: "Profil" },
    { id: "deleteSection", label: "Benutzerkonto löschen" }
];
</script>

<template>
    <Head>
        <title>Mein Benutzerkonto</title>
    </Head>
    <headline glow align="left">
        <icon name="user-settings" :size="3" />
        Mein Benutzerkonto
    </headline>

    <sticky-nav :items="navItems" />

    <dashboard-password align="right" />
    <dashboard-profile align="left" />
    <delete-account align="right" />
</template>
