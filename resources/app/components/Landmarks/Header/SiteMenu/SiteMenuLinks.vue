<script setup lang="ts">
/******************************************************************************
 * SiteMenuLinks
 * the inline, horizontal form of the site navigation — the desktop links, shown
 * at the "desktop" breakpoint and up where the header has room for them. Below
 * that this whole <nav> is hidden and SiteMenuPopover takes over. Same areas as
 * the popover (useSiteAreas).
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useSiteAreas } from "Composables/useSiteAreas";

const { t } = useI18n();
const areas = useSiteAreas();
const page = usePage();

/** Whether an area is the current one — its root path or anything beneath it — so its link gets the lit `--active` look. */
const isActive = (href: string): boolean => {
    const path = page.url.split("?")[0];
    return path === href || path.startsWith(`${href}/`);
};
</script>

<template>
    <nav class="site-menu-links" :aria-label="t('header.siteMenu.nav')">
        <ul class="site-menu-links__list">
            <li v-for="area in areas" :key="area.href">
                <Link
                    class="site-menu-links__link"
                    :class="{ 'site-menu-links__link--active': isActive(area.href) }"
                    :href="area.href"
                >
                    <icon :name="area.icon" :size="1" />
                    {{ area.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.site-menu-links {
    display: none;

    // shown only where the header has room for the inline links.
    @include m.mq("desktop") {
        display: block;
    }

    &__list {
        display: flex;
        align-items: center;
        flex-wrap: wrap;

        padding: 0;

        margin: 0;
        gap: 1ch;

        list-style: none;
    }

    &__link {
        display: inline-flex;
        align-items: center;

        border: map.get(s.$c-site-menu-links, "border") solid map.get(c.$c-site-menu-links, "border");
        gap: 0.5ch;

        background-color: map.get(c.$c-site-menu-links, "background");
        color: map.get(c.$c-site-menu-links, "surface");
        border-radius: map.get(s.$c-site-menu-links, "radius");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-site-menu-links linear,
                box-shadow ti.$c-site-menu-links linear,
                color ti.$c-site-menu-links linear;
        }

        &:hover {
            background-color: map.get(c.$c-site-menu-links, "background-hover");
            color: map.get(c.$c-site-menu-links, "surface-hover");
        }

        // active area: give the current link the UserMenu popover's "logged in"
        // (--highlighted) fill + surface, lit with the popover's open-state halo,
        // so the current section reads as "lit up". Border drops away for the glow.
        &--active {
            background-color: map.get(c.$c-site-menu-links, "active-background");
            color: map.get(c.$c-site-menu-links, "active-surface");
            border-color: transparent;

            // the same two-layer neon halo an open popover trigger has.
            box-shadow:
                0 0 0.6em 0.1em map.get(c.$c-site-menu-links, "active-glow"),
                0 0 1.5em 0.25em map.get(c.$c-site-menu-links, "active-glow");
        }

        @include m.mqset(
            "padding",
            #{map.get(s.$c-site-menu-links, "padding", "base") * 0.25
                map.get(s.$c-site-menu-links, "padding", "base") * 0.5},
            #{map.get(s.$c-site-menu-links, "padding", "portrait") * 0.25
                map.get(s.$c-site-menu-links, "padding", "portrait") * 0.5},
            #{map.get(s.$c-site-menu-links, "padding", "landscape") * 0.25
                map.get(s.$c-site-menu-links, "padding", "landscape") * 0.5},
            #{map.get(s.$c-site-menu-links, "padding", "desktop") * 0.25
                map.get(s.$c-site-menu-links, "padding", "desktop") * 0.5}
        );
    }
}
</style>
