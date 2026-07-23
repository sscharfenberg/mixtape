<script setup lang="ts">
/******************************************************************************
 * ArtistsPage
 * The Music → Artists sub-section (ALL artists), reached at /music/artists
 * (route `music.artists`) and linked from the ArtistsWidget footer. Stub for
 * now — a glowing headline over placeholder copy, pending the real listing.
 * (A single artist's detail page will be a separate ArtistPage later.)
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";

const { t } = useI18n();
</script>

<template>
    <Head :title="t('music.widgets.artists')" />
    <headline glow>{{ t("music.widgets.artists") }}</headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore omnis
            pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum.
        </p>
    </container>
</template>
