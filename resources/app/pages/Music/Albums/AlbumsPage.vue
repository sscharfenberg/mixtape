<script setup lang="ts">
/******************************************************************************
 * AlbumsPage
 * The Music → Albums sub-section (ALL albums), reached at /music/albums (route
 * `music.albums`) and linked from the AlbumsWidget footer. Stub for now — a
 * glowing headline over placeholder copy, pending the real listing. (A single
 * album's detail page will be a separate AlbumPage later.)
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";

const { t } = useI18n();
</script>

<template>
    <Head :title="t('music.widgets.albums')" />
    <headline glow>{{ t("music.widgets.albums") }}</headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore omnis
            pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum.
        </p>
    </container>
</template>
