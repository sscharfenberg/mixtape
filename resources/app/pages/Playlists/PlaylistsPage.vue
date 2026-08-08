<script setup lang="ts">
/******************************************************************************
 * PlaylistsPage
 * The Playlists area, reached at /playlists (route `playlists`, behind auth) and
 * linked from the header site menu (useSiteAreas). Lists the reader's OWN saved
 * playlists over a primary link to the create form.
 *
 * The create link sits ABOVE the list rather than at its end, because the empty
 * state is the normal first visit: a new account has no playlists, and the one
 * action the page offers should not be below a paragraph explaining why the page
 * is blank. It stays in the same place once the list fills, so it never moves.
 *
 * STRUCTURE, NOT YET STYLE. The row markup is a plain `ul.playlist__list` of
 * `li.playlist`s and deliberately carries no layout of its own — the shape is
 * settled here, the look comes later. One thing is not a styling choice and is
 * fixed here: PlaylistMenu is a SIBLING of the row's <a>, because an <a> may not
 * contain interactive content (see the component's banner).
 *
 * Every value arrives raw — a plain count, seconds, ISO-8601 instants — and is
 * formatted here against the VIEWER's locale and timezone, which the server cannot
 * know. Two facts are conditional, and on facts about the data rather than about
 * formatting: a playlist with no description shows none, and `updatedAt` is null
 * until something actually changes, so an untouched playlist carries no "changed"
 * tile at all.
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { PlaylistEntry } from "Types/playlists";
import { formatDateTime, formatDuration } from "Utils/formatting";
import PlaylistMenu from "./PlaylistMenu.vue";

defineProps<{
    /** The reader's own playlists, in their own order — empty for a fresh account. */
    playlists: PlaylistEntry[];
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "header.siteMenu.playlists", icon: "playlist" }]);

/**
 * An ISO-8601 instant in the reader's locale and timezone.
 *
 * Returns "" rather than null for a missing or unparseable one, because that is what
 * FactPair's caller contract wants: an empty value is a tile the caller should not
 * render, and `v-if` on "" reads the same as on null without a second type.
 */
const dateOf = (iso: string | null): string => formatDateTime(iso, locale.value) ?? "";

/**
 * How long a playlist plays, as a human breakdown ("1 Stunde, 12 Minuten").
 *
 * `formatDuration` rather than `formatClock`: a total is read as an amount of time, not
 * as a position on a timeline, and it grows an hours part on its own for a long playlist
 * while still saying plain minutes for a short one. The same call StatsWidget makes for
 * the collection's playtime, so the two agree.
 *
 * Empty for a playlist with nothing in it — the server sends null there (SUM over no
 * rows), and "0 Sekunden" beside a track count of 0 says nothing twice.
 */
const playtimeOf = (seconds: number | null): string =>
    seconds === null || seconds === 0 ? "" : formatDuration(seconds, (key, count) => t(`common.duration.${key}`, count));
</script>

<template>
    <Head :title="t('header.siteMenu.playlists')" />
    <headline glow>
        <icon name="playlist" :size="3" />
        {{ t("header.siteMenu.playlists") }}
    </headline>

    <container>
        <p class="playlists__actions">
            <Link href="/playlists/create" class="btn btn-primary">
                <icon name="playlist" :size="1" />
                <span>{{ t("playlists.createLink") }}</span>
            </Link>
        </p>

        <ul v-if="playlists.length" class="playlist__list">
            <li v-for="playlist in playlists" :key="playlist.id" class="playlist">
                <!-- PLACEHOLDER destination: the playlist detail page does not exist yet. -->
                <a class="playlist__link" href="https://www.google.com">
                    <span class="playlist__title">{{ playlist.name }}</span>
                </a>
                <playlist-menu :playlist="playlist" />
                <span v-if="playlist.description" class="playlist__description">{{ playlist.description }}</span>
                <!-- role="list" because the marker is styled away by FactPair's hosts, and
                     Safari/VoiceOver drops list semantics from a list without markers. -->
                <ul class="playlist__facts" role="list">
                    <fact-pair :label="t('playlists.facts.tracks')" :value="String(playlist.tracks)" icon="song" />
                    <fact-pair
                        v-if="playtimeOf(playlist.duration)"
                        :label="t('playlists.facts.duration')"
                        :value="playtimeOf(playlist.duration)"
                        icon="duration"
                    />
                    <fact-pair
                        v-if="dateOf(playlist.createdAt)"
                        :label="t('playlists.facts.createdAt')"
                        :value="dateOf(playlist.createdAt)"
                        icon="recent"
                    />
                    <fact-pair
                        v-if="dateOf(playlist.updatedAt)"
                        :label="t('playlists.facts.updatedAt')"
                        :value="dateOf(playlist.updatedAt)"
                        icon="refresh"
                    />
                </ul>
            </li>
        </ul>

        <template v-else>
            <headline :size="3">{{ t("playlists.empty.headline") }}</headline>
            <p>{{ t("playlists.empty.text") }}</p>
        </template>
    </container>
</template>

<style scoped lang="scss">
/* The rows are deliberately unstyled for now — only the gutter under the create button
   is set, and it reads the BUTTON's own clearance token rather than a spacing rung: the
   neon halo and its reflection paint well outside the button's box and take part in no
   layout, so this is the button's metric, not a gap the page chose. */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.playlists__actions {
    margin-block: 0 map.get(s.$c-button, "halo-clearance");
}
</style>
