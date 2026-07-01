<script setup lang="ts">
import AppHeaderLogo from "Components/Landmarks/Header/AppHeaderLogo.vue";
</script>

<template>
    <header class="app-header">
        <app-header-logo />
    </header>
</template>

<style scoped lang="scss">
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

.app-header {
    position: relative;
    z-index: z.$c-header;
}
</style>
