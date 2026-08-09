<script setup lang="ts">
/******************************************************************************
 * PlaylistTracks
 * A playlist's entries, in the reader's own order: one row per track, each with the facts
 * that place it and the two things you can do to it on its own — play it, or queue it.
 *
 * Page-local rather than a shared component, like GenreArtists and unlike Discography: a
 * row here is built around a PLAYLIST ENTRY (its own id, its position in someone's running
 * order), which is not a thing an album or artist page has. If a second caller ever wants
 * it, that is the moment to promote it.
 *
 * A ROW STAYS A ROW AT EVERY WIDTH, which is the one layout decision worth naming because
 * the Discography does the opposite (it becomes a grid of cards from `landscape` up). A
 * discography is a set of records you browse by their artwork; a playlist is a running
 * order you read down, and the ORDER is the information — cards in a fluid column count
 * reflow it into something you have to reconstruct. So the row only ever wraps, never
 * re-flows. The frame around it — rounded corners, grey edge, subtle fill, the control-neon
 * halo under the pointer — is the genre page's artist card, so a listed thing looks the same
 * wherever it is listed.
 *
 * NEITHER BUTTON COSTS A REQUEST. Each row already IS a queue entry (PlaylistController
 * sends the whole playlist as one, since every entry of it is on screen anyway), so play
 * and enqueue act on the object in hand. That is also why they are buttons rather than a
 * per-row menu: with nothing to fetch there is nothing to hide behind a click.
 *
 * THE TITLE IS THE LINK, not the row — and that is forced rather than chosen. A DataTable
 * row and a Discography tile can make the whole box the click target; this row holds two
 * buttons, and an <a> may not contain interactive content (the same constraint the playlists
 * listing works around with a stretched `::after`, which is unavailable here for exactly the
 * same reason — it would swallow the controls). So the title alone navigates.
 *
 * PLAY MEANS REPLACE, matching SubjectMenu and every player: it empties the queue and puts
 * this one track in it. Queueing the whole playlist and jumping to the row would be a
 * different verb, and the hero's menu is where the whole-playlist verbs live.
 *
 * Every value arrives raw — seconds, a plain year — and is formatted here.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { formatClock } from "Utils/formatting";

/**
 * One entry of the playlist, as PlaylistController shaped it: a queue entry — so the row's
 * buttons can hand it straight to the player — plus the two things a row shows and a queue
 * entry has no use for.
 */
export interface PlaylistTrackRow extends QueueTrack {
    /**
     * The PIVOT row's id, not the track's, and the key this list is rendered by: the same
     * track may sit in a playlist twice, so `id` is not unique down the list.
     */
    entryId: string;
    /** The album's release year, or null for a loose file or an untagged rip. */
    year: number | null;
}

defineProps<{
    /**
     * The entries, already in the reader's own order (`playlist_tracks.position`). Rendered
     * in the order given — the ordering is the playlist itself, so re-sorting here would
     * only let the page and the data disagree.
     */
    tracks: PlaylistTrackRow[];
}>();

const { t } = useI18n();
const { playNow, enqueue } = usePlayerQueue();
const { play } = usePlayerAudio();
const { addToast } = useToast();

/**
 * How long the track runs, as a clock. Empty for a file whose tags carried no duration,
 * which drops the chip rather than printing "0:00" — `formatClock` is null-in/null-out.
 */
const playingTime = (track: PlaylistTrackRow): string => formatClock(track.duration) ?? "";

/**
 * Replace the queue with this one track and start it.
 *
 * `play()` is called explicitly, and it matters: loading a track does not start it, and a
 * browser only allows playback from a user gesture — this click is that gesture, so the
 * call has to happen inside the handler rather than in a watcher somewhere later. The same
 * shape SubjectMenu's own play uses.
 */
function playTrack(track: PlaylistTrackRow): void {
    playNow([track]);
    play();
}

/**
 * Append this track to the queue, leaving whatever is playing alone.
 *
 * Toasts, because nothing else on screen would move: the queue panel is closed by default,
 * so without a word the button reads as having done nothing. The count is passed to the
 * same pluralised message the hero's menu uses, so one enqueue reads identically wherever
 * it was pressed.
 */
function enqueueTrack(track: PlaylistTrackRow): void {
    enqueue(track);
    addToast(t("music.subjectMenu.enqueued", 1), "success", 3000);
}
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, N items" before the rows, which
         is the one thing a bare stack of <div>s would say worse. -->
    <ul v-if="tracks.length > 0" class="playlist-tracks" :aria-label="t('playlists.detail.label')">
        <li v-for="track in tracks" :key="track.entryId" class="playlist-tracks__item">
            <!-- The title is the row's only NAVIGATION — unlike a DataTable row or a
                 Discography tile, the row itself is not a link and cannot be: it holds two
                 buttons, and an <a> may not contain interactive content. `prefetch` warms the
                 song page on hover, as every other listing does. -->
            <Link :href="track.href" class="playlist-tracks__name" prefetch>{{ track.name }}</Link>

            <!-- One chip per fact, each dropped rather than shown empty when the tags don't
                 carry it: a file crediting nobody, one filed under no album, an untagged rip
                 with no year. The chips are the Discography's and the artist card's — the
                 same object, at the size a secondary fact deserves. -->
            <span class="playlist-tracks__meta">
                <span v-if="track.artist" class="playlist-tracks__fact">{{ track.artist }}</span>
                <span v-if="track.album" class="playlist-tracks__fact">{{ track.album }}</span>
                <span v-if="track.year !== null" class="playlist-tracks__fact">{{ track.year }}</span>
                <span v-if="playingTime(track)" class="playlist-tracks__fact">{{ playingTime(track) }}</span>
            </span>

            <!-- Icon only. The verb is carried by the tooltip on hover and by `aria-label`
                 for everyone who never sees one — a row of thirty entries with the words
                 "Abspielen" and "In die Warteschlange" printed sixty times would read as a
                 wall of controls with the titles somewhere behind it. -->
            <span class="playlist-tracks__controls">
                <button
                    type="button"
                    class="playlist-tracks__control"
                    v-tooltip="t('playlists.detail.playHint')"
                    :aria-label="t('playlists.detail.play', { name: track.name })"
                    @click="playTrack(track)"
                >
                    <icon name="play" :size="1" />
                </button>
                <button
                    type="button"
                    class="playlist-tracks__control"
                    v-tooltip="t('playlists.detail.enqueueHint')"
                    :aria-label="t('playlists.detail.enqueue', { name: track.name })"
                    @click="enqueueTrack(track)"
                >
                    <icon name="playlist" :size="1" />
                </button>
            </span>
        </li>
    </ul>
    <p v-else>{{ t("playlists.detail.empty") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* A column of rows, at every width — see the banner for why this never becomes a grid. The
   UA marker and padding go (normalize.css leaves lists alone). */
.playlist-tracks {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-playlist-tracks, "gap");

    list-style: none;
}

/* ONE ENTRY. Three parts on one line: the title takes the slack, the facts sit against the
   controls, and the controls hold the trailing edge — so the buttons line up down the list
   as a column however long the titles run.

   `align-items: center` because the row is a single line in the overwhelming majority of
   cases, and where the facts wrap to a second line the buttons still read as belonging to
   the row rather than to its first line.

   The frame is the genre page's artist card, re-picked from the globals (see the colour
   partial). */
.playlist-tracks__item {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    box-sizing: border-box;

    padding: map.get(s.$c-playlist-tracks, "row-padding");
    border: map.get(s.$c-playlist-tracks, "border") solid map.get(c.$c-playlist-tracks, "border");
    gap: map.get(s.$c-playlist-tracks, "row-gap");

    background-color: map.get(c.$c-playlist-tracks, "background");
    border-radius: map.get(s.$c-playlist-tracks, "radius");

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-playlist-tracks ease-out,
            box-shadow ti.$c-playlist-tracks ease-out;
    }

    /* The house treatment, identical to the artist card, the Discography tile and the
       DataTable's clickable rows: the two-layer control-neon halo over a wash that only
       SHIFTS the row's existing fill. Both layers are soft and em-based — written as a hard
       ring plus a tight blur it reads as an outline drawn around the row rather than as the
       row lighting up.

       `:focus-within` as well as `:hover`, which the card one tab away does not need: this
       row holds real controls, so it can be reached by keyboard without a pointer ever
       touching it. `position: relative` so the glow paints above the neighbouring rows
       rather than under them. */
    &:hover,
    &:focus-within {
        position: relative;

        background-color: map.get(c.$c-playlist-tracks, "hover-background");
        box-shadow:
            0 0 0.6em 0.1em map.get(c.$c-playlist-tracks, "glow"),
            0 0 1.5em 0.25em map.get(c.$c-playlist-tracks, "glow");
    }
}

/* The title, and the row's link to the song. Takes the slack, so the facts and the controls
   sit against the trailing edge. `min-width: 0` plus `overflow-wrap: anywhere` is what keeps
   a long unbreakable title — a German compound, a filename-as-title from an untagged rip —
   wrapping inside the row instead of pushing the buttons off its edge. The same flex trap
   the player bar's meta column and the queue row both document.

   NOT the app's `.text-link` treatment, and underlined only on hover or focus: the house rule
   every listing follows (see the Songs table's title cell). A permanent underline under the
   bold title of every row is a page of ruled lines, and the row already lights up under the
   pointer — but it is more load-bearing here than in a DataTable, where the whole row is the
   click target, because here this is the only thing that navigates at all. */
.playlist-tracks__name {
    overflow-wrap: anywhere;
    min-width: 0;
    flex: 1 1 auto;

    color: inherit;

    font-weight: bold;
    text-decoration: none;

    &:hover,
    &:focus-visible {
        text-decoration: underline;
    }
}

.playlist-tracks__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;

    gap: map.get(s.$c-playlist-tracks, "meta-gap");

    color: map.get(c.$c-playlist-tracks, "surface-meta");
}

/* Each fact as its own chip — the same object the artist card's numbers and the
   Discography's facts are. `tabular-nums` so the years and clocks line up down the list
   without also monospacing the artist and album beside them. */
.playlist-tracks__fact {
    padding: map.get(s.$c-playlist-tracks, "meta-padding");

    background-color: map.get(c.$c-playlist-tracks, "meta-background");

    border-radius: map.get(s.$c-playlist-tracks, "meta-radius");

    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

/* `margin-inline-start: auto` is what keeps the two buttons on the TRAILING edge at every
   width, and it is not redundant with the title's `flex: 1`. That only absorbs the slack
   while all three parts share one line; at phone width the facts fill the line and the
   controls wrap onto their own — where they are the only item, so without this they sit at
   its leading edge, under the title, reading as a third row of content rather than as the
   row's controls. */
.playlist-tracks__controls {
    display: flex;
    align-items: center;

    flex: 0 0 auto;
    margin-inline-start: auto;
    gap: map.get(s.$c-playlist-tracks, "control-gap");
}

/* Quiet at rest, neon under the pointer or on focus — see the colour partial for why the
   resting state is deliberately dim. The padding is what makes it findable: a bare glyph is
   a 19px target otherwise, which is what the play queue's grip and the listing's drag
   handle both had to widen.

   `:focus-visible` rather than `:focus`, so a pointer click doesn't leave a button lit after
   the pointer has gone; the outline is what a keyboard user gets on top of the wash, since
   the row's own halo says "somewhere in here" and cannot say which of the two. */
.playlist-tracks__control {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-playlist-tracks, "control-padding");
    border: 0;

    background: none;
    color: map.get(c.$c-playlist-tracks, "control");

    border-radius: map.get(s.$c-playlist-tracks, "control-radius");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            color ti.$c-playlist-tracks linear,
            background-color ti.$c-playlist-tracks linear;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-playlist-tracks, "control-background-active");
        color: map.get(c.$c-playlist-tracks, "control-active");
    }

    &:focus-visible {
        outline: 2px solid currentcolor;
    }
}
</style>
