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

// Per-breakpoint link padding — a quarter of the token block-wise, half inline.
// Pulled into locals so each mqset argument is a short single token: the inline
// `map.get(...) * 0.25 map.get(...) * 0.5` form overruns 120 cols and reflows
// mid-`*`, tripping stylelint's operator-no-newline-after (which --fix can't repair).
$link-pad: map.get(s.$c-site-menu-links, "padding");
$link-pad-base: map.get($link-pad, "base") * 0.25 map.get($link-pad, "base") * 0.5;
$link-pad-portrait: map.get($link-pad, "portrait") * 0.25 map.get($link-pad, "portrait") * 0.5;
$link-pad-landscape: map.get($link-pad, "landscape") * 0.25 map.get($link-pad, "landscape") * 0.5;
$link-pad-desktop: map.get($link-pad, "desktop") * 0.25 map.get($link-pad, "desktop") * 0.5;

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
                color ti.$c-site-menu-links linear;
        }

        &:hover {
            background-color: map.get(c.$c-site-menu-links, "background-hover");
            color: map.get(c.$c-site-menu-links, "surface-hover");
        }

        // active area: give the current link the UserMenu popover's "logged in"
        // (--highlighted) fill + surface, so the current section reads as "lit up".
        &--active {
            background-color: map.get(c.$c-site-menu-links, "active-background");
            color: map.get(c.$c-site-menu-links, "active-surface");
            border-color: map.get(c.$c-site-menu-links, "active-border");
        }

        @include m.mqset("padding", $link-pad-base, $link-pad-portrait, $link-pad-landscape, $link-pad-desktop);
    }
}
</style>
