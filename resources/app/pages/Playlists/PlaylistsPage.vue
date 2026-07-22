<script setup lang="ts">
/******************************************************************************
 * PlaylistsPage
 * The Playlists browse area, reached at /playlists (route `playlists`, behind
 * auth) and linked from the header site menu (useSiteAreas). Scaffold only: a
 * glowing headline over placeholder copy, pending the real playlists UI.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";

const { t } = useI18n();
</script>

<template>
    <Head :title="t('header.siteMenu.playlists')" />
    <headline glow>
        <icon name="playlist" :size="3" />
        {{ t("header.siteMenu.playlists") }}
    </headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore omnis
            pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum neque odio quod
            reiciendis. Amet consequatur delectus dolorum eos in magni obcaecati recusandae tempora!
        </p>
    </container>
</template>
