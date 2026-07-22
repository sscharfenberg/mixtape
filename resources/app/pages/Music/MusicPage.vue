<script setup lang="ts">
/******************************************************************************
 * MusicPage
 * The Music browse area (/music, route `music`, behind auth; linked from the
 * header site menu). Lays out four browse widgets — Albums, Artists, Genres,
 * Songs — in a WidgetGroup; each toggles between its latest and random entries.
 * The data arrives as Inertia props from MusicController (both sets per widget,
 * so the toggles are client-side).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import AlbumsWidget from "Components/UI/Widget/Consumers/AlbumsWidget.vue";
import ArtistsWidget from "Components/UI/Widget/Consumers/ArtistsWidget.vue";
import GenresWidget from "Components/UI/Widget/Consumers/GenresWidget.vue";
import SongsWidget from "Components/UI/Widget/Consumers/SongsWidget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { AlbumEntry, SongEntry, TaxonomyEntry, WidgetModes } from "Types/music";

const { t } = useI18n();

defineProps<{
    albums: WidgetModes<AlbumEntry>;
    artists: WidgetModes<TaxonomyEntry>;
    genres: WidgetModes<TaxonomyEntry>;
    songs: WidgetModes<SongEntry>;
}>();
</script>

<template>
    <Head :title="t('header.siteMenu.music')" />
    <headline glow>
        <icon name="music" :size="3" />
        {{ t("header.siteMenu.music") }}
    </headline>

    <container>
        <widget-group>
            <albums-widget :latest="albums.latest" :random="albums.random" />
            <artists-widget :latest="artists.latest" :random="artists.random" />
            <genres-widget :latest="genres.latest" :random="genres.random" />
            <songs-widget :latest="songs.latest" :random="songs.random" />
        </widget-group>
    </container>
</template>
