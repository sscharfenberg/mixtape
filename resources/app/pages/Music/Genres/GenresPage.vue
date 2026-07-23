<script setup lang="ts">
/******************************************************************************
 * GenresPage
 * The Music → Genres sub-section (ALL genres), reached at /music/genres (route
 * `music.genres`) and linked from the GenresWidget footer. Stub for now — a
 * glowing headline over placeholder copy, pending the real listing. (A single
 * genre's detail page will be a separate GenrePage later.)
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";

const { t } = useI18n();
</script>

<template>
    <Head :title="t('music.widgets.genres')" />
    <headline glow>{{ t("music.widgets.genres") }}</headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore omnis
            pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum.
        </p>
    </container>
</template>
