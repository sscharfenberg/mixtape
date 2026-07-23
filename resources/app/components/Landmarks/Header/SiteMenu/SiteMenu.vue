<script setup lang="ts">
/******************************************************************************
 * SiteMenu
 * the top-level site navigation in the app header. It offers the same areas
 * (see useSiteAreas) in two presentations that swap by viewport width:
 * SiteMenuLinks (an inline row, desktop and up) and SiteMenuPopover (an icon-
 * only popover, below desktop). Only one is ever shown, and each carries its
 * own labelled <nav> landmark, so this wrapper stays semantically neutral.
 *
 * Rendered only for a signed-in user: every area it links to lives behind the
 * `auth` middleware, so there's nothing here for a guest to reach.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import SiteMenuLinks from "Components/Landmarks/Header/SiteMenu/SiteMenuLinks.vue";
import SiteMenuPopover from "Components/Landmarks/Header/SiteMenu/SiteMenuPopover.vue";

const page = usePage();
/** The authenticated user (null for guests) — gates the whole site menu. */
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div v-if="user" class="site-menu">
        <site-menu-links />
        <site-menu-popover />
    </div>
</template>
