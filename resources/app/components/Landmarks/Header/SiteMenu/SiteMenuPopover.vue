<script setup lang="ts">
/******************************************************************************
 * SiteMenuPopover
 * the compact form of the site navigation — an icon-only popover (mirroring
 * UserMenu) linking to the top-level areas (useSiteAreas). Shown below the
 * "desktop" breakpoint; at desktop and up the whole <nav> is hidden and
 * SiteMenuLinks shows the inline links instead. Labels come from the i18n
 * catalog.
 *****************************************************************************/
import { Link, usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useSiteAreas } from "Composables/useSiteAreas";

const { t } = useI18n();
const areas = useSiteAreas();
const page = usePage();

/** Whether an area is the current one — its root path or anything beneath it — so its item gets the lit `--active` look (mirrors SiteMenuLinks). */
const isActive = (href: string): boolean => {
    const path = page.url.split("?")[0];
    return path === href || path.startsWith(`${href}/`);
};

/** Programmatically hides the site-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("siteMenu");
    if (dialog !== null) dialog.hidePopover();
}
</script>

<template>
    <nav class="site-menu-popover" :aria-label="t('header.siteMenu.nav')">
        <pop-over
            icon="navigation"
            :aria-label="t('header.siteMenu.open')"
            reference="siteMenu"
            class-string="popover-button--rounded"
            width="20ch"
        >
            <ul class="popover-list">
                <li v-for="area in areas" :key="area.href">
                    <Link
                        class="popover-list-item"
                        :class="{ 'popover-list-item--active': isActive(area.href) }"
                        :href="area.href"
                        @click="closePopover"
                    >
                        <icon :name="area.icon" :size="1" />
                        {{ area.label }}
                    </Link>
                </li>
            </ul>
        </pop-over>
    </nav>
</template>

<style lang="scss" scoped>
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;

.site-menu-popover {
    // the inline SiteMenuLinks take over wherever the header has room for them.
    @include m.mq("desktop") {
        display: none;
    }
}

// current area — the same "logged in" highlighted fill + surface the desktop
// SiteMenuLinks uses (shared site-menu tokens), so the compact menu marks the
// current section the same way. Held through hover, since it's the current page.
.popover-list-item--active {
    &,
    &:hover {
        background-color: map.get(c.$c-site-menu-links, "active-background");
        color: map.get(c.$c-site-menu-links, "active-surface");
    }
}
</style>
