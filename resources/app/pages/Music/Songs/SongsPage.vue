<script setup lang="ts">
/******************************************************************************
 * SongsPage
 * The Music → Songs sub-section (ALL songs), reached at /music/songs (route
 * `music.songs`) and linked from the SongsWidget footer. Stub for now — a
 * glowing headline over placeholder copy, pending the real listing. (A single
 * song's detail page will be a separate SongPage later.)
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";

const { t } = useI18n();
</script>

<template>
    <Head :title="t('music.widgets.songs')" />
    <headline glow>{{ t("music.widgets.songs") }}</headline>
    <container>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Asperiores commodi dolor eum, labore omnis
            pariatur quaerat quis veritatis? Animi aperiam consectetur deleniti facilis hic id ipsum.
        </p>
    </container>
</template>
