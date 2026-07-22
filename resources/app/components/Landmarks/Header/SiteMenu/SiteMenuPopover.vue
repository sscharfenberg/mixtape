<script setup lang="ts">
/******************************************************************************
 * SiteMenuPopover
 * the compact, always-available form of the site navigation — an icon-only
 * popover (mirroring UserMenu) that links to the top-level areas. It is the
 * navigation shown when the horizontal SiteMenuLinks don't fit; on wide
 * viewports it can be hidden in favour of those inline links. The area list is
 * the single source of truth here; icons are placeholders until the real set is
 * designed. Labels come from the i18n catalog.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";

const { t } = useI18n();

/** Top-level areas, in header order. `icon` is a placeholder until the real icons are designed. */
const areas: { href: string; label: string; icon: string }[] = [
    { href: "/music", label: t("header.siteMenu.music"), icon: "question" },
    { href: "/audiobooks", label: t("header.siteMenu.audiobooks"), icon: "question" },
    { href: "/podcasts", label: t("header.siteMenu.podcasts"), icon: "question" },
    { href: "/playlists", label: t("header.siteMenu.playlists"), icon: "question" }
];

/** Programmatically hides the site-menu popover by its DOM id (on item click). */
function closePopover(): void {
    const dialog = document.getElementById("siteMenu");
    if (dialog !== null) dialog.hidePopover();
}
</script>

<template>
    <pop-over
        icon="question"
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
</template>
