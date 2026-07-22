<script setup lang="ts">
/******************************************************************************
 * SiteMenuLinks
 * the inline, horizontal form of the site navigation — the desktop links, shown
 * at the "desktop" breakpoint and up where the header has room for them. Below
 * that this whole <nav> is hidden and SiteMenuPopover takes over. Same areas as
 * the popover (useSiteAreas).
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useSiteAreas } from "Composables/useSiteAreas";

const { t } = useI18n();
const areas = useSiteAreas();
</script>

<template>
    <nav class="site-menu-links" :aria-label="t('header.siteMenu.nav')">
        <ul class="site-menu-links__list">
            <li v-for="area in areas" :key="area.href">
                <Link class="site-menu-links__link" :href="area.href">
                    <icon :name="area.icon" :size="1" />
                    {{ area.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>

<style lang="scss" scoped>
@use "Abstracts/mixins" as m;

.site-menu-links {
    display: none;

    // shown only where the header has room for the inline links.
    @include m.mq("desktop") {
        display: block;
    }

    &__list {
        display: flex;
        align-items: center;

        padding: 0;

        margin: 0;
        gap: 1ch;

        list-style: none;
    }

    &__link {
        display: inline-flex;
        align-items: center;

        gap: 0.5ch;
    }
}
</style>
