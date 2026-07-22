<script setup lang="ts">
/******************************************************************************
 * FullLayout
 * The app's default page layout (wired in main.ts as `layout: () => FullLayout`,
 * so every page renders inside it). Holds the persistent chrome — AppHeader,
 * the AppMain content landmark that wraps the page via <slot />, and AppFooter
 * — plus the single ToastContainer, mounted once here so flash / toast messages
 * surface on every page.
 *****************************************************************************/
import AppFooter from "Components/Landmarks/Footer/AppFooter.vue";
import AppHeader from "Components/Landmarks/Header/AppHeader.vue";
import AppMain from "Components/Landmarks/Main/AppMain.vue";
import ToastContainer from "Components/UI/ToastContainer.vue";
</script>

<template>
    <app-header />
    <app-main>
        <slot />
    </app-main>
    <app-footer />
    <toast-container />
</template>
