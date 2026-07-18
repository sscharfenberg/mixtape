<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";

const { t } = useI18n();

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <Link href="/"><img src="./logo.svg" :alt="t('header.logoAlt', { appName })" /></Link>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

img {
    width: 5ch;
    height: auto;

    @include m.mq("portrait") {
        width: 6ch;
    }

    @include m.mq("landscape") {
        width: 7ch;
    }

    @include m.mq("desktop") {
        width: 8ch;
    }
}
</style>
