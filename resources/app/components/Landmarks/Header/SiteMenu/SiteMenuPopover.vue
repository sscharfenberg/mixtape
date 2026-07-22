<script setup lang="ts">
/******************************************************************************
 * SiteMenuPopover
 * the compact form of the site navigation — an icon-only popover (mirroring
 * UserMenu) linking to the top-level areas (useSiteAreas). Shown below the
 * "desktop" breakpoint; at desktop and up the whole <nav> is hidden and
 * SiteMenuLinks shows the inline links instead. Labels come from the i18n
 * catalog.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useSiteAreas } from "Composables/useSiteAreas";

const { t } = useI18n();
const areas = useSiteAreas();

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
                    <Link class="popover-list-item" :href="area.href" @click="closePopover">
                        <icon :name="area.icon" :size="1" />
                        {{ area.label }}
                    </Link>
                </li>
            </ul>
        </pop-over>
    </nav>
</template>

<style lang="scss" scoped>
@use "Abstracts/mixins" as m;

.site-menu-popover {
    // the inline SiteMenuLinks take over wherever the header has room for them.
    @include m.mq("desktop") {
        display: none;
    }
}
</style>
