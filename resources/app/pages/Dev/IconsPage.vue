<script setup lang="ts">
/******************************************************************************
 * IconsPage (dev)
 * A gallery of every icon in the sprite, ported from cantrip.me's Dev/Icons
 * page. It globs the source SVGs at build time purely to harvest their file
 * names, then renders each through the shared Icon component so the list can
 * never drift from what actually ships. Not linked from anywhere — reached
 * directly at /icons (see the dev section in routes/web.php).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

/** Lazy glob — we only read the keys, never import the modules. */
const iconGlob = import.meta.glob("../../assets/icons/*.svg");
// Strip each globbed path down to its bare file name (the sprite symbol id), then sort alphabetically.
const iconNames = Object.keys(iconGlob)
    .map(path => path.replace(/^.*\/(.+)\.svg$/, "$1"))
    .sort();
</script>

<template>
    <Head><title>Icon overview</title></Head>
    <headline glow>
        Icon overview
        <template #right>{{ iconNames.length }}</template>
    </headline>
    <container>
        <div class="icon-overview">
            <div v-for="name in iconNames" :key="name" class="icon-overview__item">
                <icon :name="name" :size="4" />
                <span class="icon-overview__label">{{ name }}</span>
            </div>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;

.icon-overview {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(7rem, 1fr));

    gap: 1ch;

    &__item {
        display: flex;
        align-items: center;
        flex-direction: column;

        padding: 1ch;
        gap: 0.5rem;

        background-color: map.get(c.$p-icons, "item-background");
        border-radius: 1rem;
    }

    &__label {
        text-align: center;
        word-break: break-all;
    }
}
</style>
