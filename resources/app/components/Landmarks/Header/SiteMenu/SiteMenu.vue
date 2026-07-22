<script setup lang="ts">
/******************************************************************************
 * SiteMenu
 * the top-level site navigation in the app header. It offers the same set of
 * areas in two forms: SiteMenuLinks (inline horizontal links for wide viewports)
 * and SiteMenuPopover (an icon-only popover fallback that is always available).
 * The two are consumed here; which one shows at a given width is decided in
 * their own styles.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import SiteMenuLinks from "Components/Landmarks/Header/SiteMenu/SiteMenuLinks.vue";
import SiteMenuPopover from "Components/Landmarks/Header/SiteMenu/SiteMenuPopover.vue";

const { t } = useI18n();
</script>

<template>
    <nav class="site-menu" :aria-label="t('header.siteMenu.nav')">
        <site-menu-links />
        <site-menu-popover />
    </nav>
</template>
