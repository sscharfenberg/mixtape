<script setup lang="ts">
/******************************************************************************
 * PlaylistPage
 * One playlist's detail page, at /playlists/{id} (route `playlists.show`) — where a row of
 * the Playlists listing leads. Nested under the listing's folder like the four Music detail
 * pages: the detail view lives inside the listing it came from, mirroring the URL.
 *
 * TWO blocks, the shape every detail page here has: the hero — the playlist's name, the
 * menu that acts on it as a whole, and a fan of a few of its covers — and below it the
 * entries themselves.
 *
 * THE FAN STANDS IN FOR ARTWORK A PLAYLIST DOES NOT HAVE. A playlist is a name over other
 * people's records, so there is nothing to photograph; three of its own sleeves, picked at
 * random per visit and fanned out (CoverSleeves, the same object the genre page's artist
 * cards lead with), say what is in it better than a placeholder would. It goes in the hero's
 * `#cover` slot, which is what puts it on the trailing edge — and when the playlist's tracks
 * carry no artwork at all the component renders a single placeholder, which is precisely
 * what makes the hero draw its dashed "no artwork on file" square around it.
 *
 * THE WHOLE PLAYLIST IS ALREADY HERE, unlike the Music detail pages, whose songs tables are
 * paginated and whose hero menus therefore fetch `queueTracks` on the first press. Every
 * entry of a playlist is on screen, so its queue payload IS the page's content — which is
 * why SubjectActions is handed the tracks rather than left to go back for them, and why each
 * row can carry its own play button (PlaylistController says the same from its end).
 *
 * IT WEARS THE MUSIC DETAIL PAGES' SHAPE SINCE 2026-08-12, which it had been the last page to
 * be left out of: the name heads the page in the glowing <Headline> every listing and detail
 * page wears, the hero is left doing the one thing only it can — the sleeves and the facts —
 * and the two verbs that were behind a "…" popover are visible buttons in an ActionPanel. A
 * page's two most likely actions should not need discovering, which is the same argument the
 * four Music heroes were rebuilt on the day before.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, ref, useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import CoverSleeves from "Components/Music/CoverSleeves.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import SubjectActions from "Components/Music/SubjectActions.vue";
import ActionPanel from "Components/UI/ActionPanel.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useToast } from "Composables/useToast";
import { formatDateTime, formatDuration } from "Utils/formatting";
import PlaylistExportModal from "./PlaylistExportModal.vue";
import PlaylistTracks, { type PlaylistTrackRow } from "./PlaylistTracks.vue";

/** The playlist itself, as PlaylistController shaped it — every value raw. */
interface PlaylistDetail {
    id: string;
    name: string;
    /** The owner's blurb, or null when they left it empty (the server stores "" as null). */
    description: string | null;
    /** How many entries it holds. 0 is the normal state right after creating one. */
    tracks: number;
    /** Total playing time in raw seconds, or null when it plays for no time at all. */
    duration: number | null;
    /** ISO-8601 instant, formatted here — the server knows neither the locale nor the timezone. */
    createdAt: string | null;
    /** ISO-8601 instant of the last change, or null when nothing has happened since it was created. */
    updatedAt: string | null;
}

const props = defineProps<{
    /** The playlist being shown, with the four numbers its hero prints. */
    playlist: PlaylistDetail;
    /** Its entries, in the reader's own order, each already a queue entry. */
    tracks: PlaylistTrackRow[];
    /**
     * How often this playlist has been listened to: the reader's own listens and everybody
     * else's, as listening events (App\Services\Player\PlayCounts). Its own prop rather than
     * a member of `playlist` because PlayCountFacts refreshes exactly this key in place when a
     * track finishes. Raw counts — a zero is something the tiles leave unsaid.
     */
    plays: { own: number; others: number };
    /** What the export modal's prefix field opens with, from config via the controller. */
    exportPrefix: string;
    /**
     * Up to three cover URLs for the hero's fan, picked at random per request and one per
     * album (PlaylistController::fannedCovers). Empty when nothing in the playlist carries
     * artwork, which renders as the hero's dashed placeholder.
     */
    covers: string[];
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// The playlist's own crumb is a raw label, not a key — its name is data. The parent chip is
// the listing this row came from, matching the trail the Music detail pages set.
setBreadcrumbs([
    { labelKey: "header.siteMenu.playlists", href: "/playlists", icon: "playlist" },
    { label: props.playlist.name }
]);

/**
 * How long the playlist plays, as a human breakdown ("1 Stunde, 12 Minuten").
 *
 * `formatDuration` rather than `formatClock`, and the same call the listing's row makes so
 * the two agree: a total is read as an amount of time, not as a position on a timeline, and
 * it grows an hours part on its own for a long playlist while still saying plain minutes for
 * a short one. The ROWS below use `formatClock`, deliberately — a single track's length is
 * read against a timeline.
 *
 * Empty for a playlist that plays for no time, which drops the tile: the server sends null
 * there, and "0 Sekunden" beside a track count of 0 says nothing twice.
 */
const playtime = computed<string>(() =>
    props.playlist.duration === null || props.playlist.duration === 0
        ? ""
        : formatDuration(props.playlist.duration, (key, count) => t(`common.duration.${key}`, count))
);

/**
 * An ISO-8601 instant in the reader's locale and timezone.
 *
 * Returns "" rather than null for a missing or unparseable one, because that is FactPair's
 * caller contract: an empty value is a tile the caller should not render, and `v-if` on ""
 * reads the same as on null without a second type.
 */
const dateOf = (iso: string | null): string => formatDateTime(iso, locale.value) ?? "";

/** Whether the export modal is open. Mounted only while it is, like the dashboard's. */
const exporting = ref(false);

/**
 * The list, so the hero's Sort button can reach the verb it exposes.
 *
 * The list owns the order — it must, since a drag has to show before the server agrees — and
 * the button that sorts it lives in the hero, one component up. Reaching down for a verb is
 * the smaller seam than lifting the order into this page and drilling it back with an event
 * per gesture; PlaylistTracks' `defineExpose` says the same from its side.
 */
const rows = useTemplateRef<InstanceType<typeof PlaylistTracks>>("rows");

const { addToast } = useToast();

/**
 * Put the playlist in file order.
 *
 * NO SPINNER AND NOTHING TO WAIT FOR, which is the point: the rows carry their own `path`, so
 * the new order is worked out and rendered inside this click, and the PUT that records it is
 * the same background write a drag does. The alternative — ask the server to sort, wait, and
 * re-render — is a round trip in front of an answer the page already had.
 *
 * The toast is what makes the change legible. A list that silently rearranges itself is hard
 * to read as a result of the button just pressed, and the two outcomes are genuinely
 * different: a playlist that was ALREADY in file order changes nothing, and saying so is more
 * honest than a success message about work that did not happen.
 */
function sort(): void {
    const moved = rows.value?.sortByPath() ?? false;

    addToast(t(moved ? "playlists.sort.done" : "playlists.sort.already"), moved ? "success" : "info", 3000);
}
</script>

<template>
    <Head :title="playlist.name" />
    <!-- Outside the Container like every other page heading — its glowing border has to reach
         the window edge so the seam hides off-screen (see Container). -->
    <headline glow>
        <icon name="playlist" :size="3" />
        {{ playlist.name }}
    </headline>
    <container>
        <div class="playlist-page">
            <!-- `unframed-cover`: the fan is a fixed size, so the hero's 240px square would
                 reserve height it cannot fill — see the prop. -->
            <hero-section unframed-cover>
                <!-- Not artwork, but where artwork would be — see the banner. `hero` scale:
                     a card's 96px sleeves read as an afterthought in a panel this wide. -->
                <template #cover><cover-sleeves :covers="covers" :title="playlist.name" scale="hero" /></template>
                <!-- No #title and no #menu: the name heads the page in the <Headline> above,
                     and the two verbs that were behind the menu are visible buttons in #actions
                     now — the same move the four Music heroes made (see the banner). -->
                <!-- Only when the owner wrote one. Between the title and the facts, because it
                     says what the playlist IS and the numbers only describe it. -->
                <template v-if="playlist.description" #description>{{ playlist.description }}</template>
                <!-- The same four facts the listing's row carries, so a playlist reads the
                     same in both places. Only the track count is unconditional: a count of 0
                     is an answer about the playlist, where the other three are facts that can
                     genuinely be absent — nothing to play, and nothing changed since it was
                     made. -->
                <template #metadata>
                    <fact-pair :label="t('playlists.facts.tracks')" :value="String(playlist.tracks)" icon="song" />
                    <fact-pair
                        v-if="playtime"
                        :label="t('playlists.facts.duration')"
                        :value="playtime"
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
                    <!-- Last, and only when there is something to say: what has actually been
                         listened to comes after what the playlist IS. The same component and
                         the same position the four Music detail pages use. -->
                    <play-count-facts :plays="plays" subject="playlist" />
                </template>
                <!-- TWO ROWS, as on the Music detail pages: the tinted ActionPanel for what a
                     reader came for — play it, queue it — and under it the actions that change
                     or export the thing those facts have just identified. Handed the tracks, so
                     neither verb costs a request (see the banner).

                     `no-halo` on both of the lower pair, added with the rest of this treatment:
                     they stand on the hero's own surface rather than on the page, and a neon
                     pool spilling across it reads as a smudge (Button.vue). The panel's buttons
                     are already drawn that way. -->
                <template #actions>
                    <action-panel>
                        <subject-actions :tracks="tracks" />
                    </action-panel>
                    <Button variant="default" no-halo type="button" @click="exporting = true">
                        <icon name="file_export" :size="1" />
                        <span>{{ t("playlists.export.open") }}</span>
                    </Button>
                    <!-- No spinner and no disabled state: the sort happens in this click — see
                         `sort()`. A button that cannot be pressed twice would be describing a
                         wait that does not exist. -->
                    <Button variant="default" no-halo type="button" @click="sort">
                        <icon name="sort" :size="1" />
                        <span>{{ t("playlists.sort.open") }}</span>
                    </Button>
                </template>
            </hero-section>

            <!-- The id goes down with the entries because the list owns its own reordering,
                 and the PUT that persists it is nested under this playlist. `ref` so the hero's
                 Sort button can reach the verb it exposes. -->
            <playlist-tracks ref="rows" :playlist-id="playlist.id" :tracks="tracks" />
        </div>
    </container>

    <!-- Mounted only while open, like the dashboard's modals: the form's three fields then
         start from their defaults every time rather than remembering the last export. -->
    <playlist-export-modal
        v-if="exporting"
        :playlist-id="playlist.id"
        :default-prefix="exportPrefix"
        :tracks="tracks"
        @close="exporting = false"
    />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Stacks the page's blocks and spaces them, taking the CardGroup's own gutter
   (s.$c-card "gap") so the rhythm down the page matches the rhythm between two cards — the
   same rule, for the same reason, as the four Music detail pages. */
.playlist-page {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}
</style>
