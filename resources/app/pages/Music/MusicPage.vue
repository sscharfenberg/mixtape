<script setup lang="ts">
/******************************************************************************
 * MusicPage
 * The Music browse area (/music, route `music`, behind auth; linked from the
 * header site menu). Lays out a wide collection-stats widget plus four browse
 * widgets — Albums, Artists, Genres, Songs — in a WidgetGroup; each browse
 * widget toggles between latest, random and (for artists/genres/songs) a
 * "popular" set. The data arrives as Inertia props from MusicController; each
 * browse widget's full mode set is forwarded with `v-bind` (not key-by-key) so a
 * widget always receives every mode it supports — omitting a key here silently
 * drops that mode to the `latest` fallback in the widget.
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
import StatsWidget from "Components/UI/Widget/Consumers/StatsWidget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { AlbumEntry, ArtistEntry, CollectionStats, GenreEntry, SongEntry, WidgetModes } from "Types/music";

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// Top of the Music branch — one crumb, so the trail is just "home → Music".
setBreadcrumbs([{ labelKey: "header.siteMenu.music", icon: "music" }]);

defineProps<{
    albums: WidgetModes<AlbumEntry>;
    artists: WidgetModes<ArtistEntry>;
    genres: WidgetModes<GenreEntry>;
    songs: WidgetModes<SongEntry>;
    stats: CollectionStats;
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
            <stats-widget v-bind="stats" />
            <albums-widget v-bind="albums" />
            <artists-widget v-bind="artists" />
            <genres-widget v-bind="genres" />
            <songs-widget v-bind="songs" />
        </widget-group>
    </container>
</template>
