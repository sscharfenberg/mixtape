<script setup lang="ts">
/******************************************************************************
 * IconsPage (dev)
 * A gallery of every icon in the sprite, ported from cantrip.me's Dev/Icons
 * page. Renders each name through the shared Icon component, so the list can
 * never drift from what actually ships. Not linked from anywhere — reached
 * directly at /icons (see the dev section in routes/web.php).
 *
 * The names arrive as a prop rather than from an `import.meta.glob` of the
 * icon directory, which is how this page used to find them. A glob would pull
 * all 55 SVGs into the Vite build as emitted assets for the image optimizer to
 * re-optimize, even though they only ever ship inlined in the sprite — see
 * IconsController for the full reasoning.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

const { setBreadcrumbs } = useBreadcrumbs();
// A raw label, not an i18n key: this dev-only page is English-only by design, so
// there is nothing in the catalogs to translate it with.
setBreadcrumbs([{ label: "Icon overview", icon: "system" }]);

defineProps<{
    /** Sprite symbol ids (the icon files' bare names), already sorted by the controller. */
    iconNames: string[];
}>();
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

        background-color: map.get(c.$c-icon-overview, "item-background");
        border-radius: 1rem;
    }

    &__label {
        text-align: center;
        word-break: break-all;
    }
}
</style>
