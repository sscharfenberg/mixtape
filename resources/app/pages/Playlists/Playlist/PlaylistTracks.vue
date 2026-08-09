<script setup lang="ts">
/******************************************************************************
 * PlaylistTracks
 * A playlist's entries, in the reader's own order: one row per track, with the facts that
 * place it, a grip to move it, and one button that starts the playlist here.
 *
 * Page-local rather than a shared component, like GenreArtists and unlike Discography: a row
 * here is built around a PLAYLIST ENTRY (its own id, its position in someone's running
 * order), which is not a thing an album or artist page has.
 *
 * A ROW STAYS A ROW AT EVERY WIDTH, which is the one layout decision worth naming because
 * the Discography does the opposite (it becomes a grid of cards from `landscape` up). A
 * discography is a set of records you browse by their artwork; a playlist is a running order
 * you read down, and the ORDER is the information — cards in a fluid column count reflow it
 * into something you have to reconstruct. So the row only ever wraps, never re-flows. The
 * frame around it is the genre page's artist card, so a listed thing looks the same wherever
 * it is listed.
 *
 * WHAT A ROW SHOWS GROWS WITH THE VIEWPORT, one fact per breakpoint (owner's call). A phone
 * gets the grip, the title and the play button and nothing else — the title is what a reader
 * picks a track by, and four chips under it turn a list you scan into a list you scroll. Then
 * `portrait` adds the artist, `landscape` the album and the artwork, `desktop` the runtime,
 * and `full` the year. The order is by how much each one tells you apart from the title.
 *
 * ONE BUTTON, AND IT MEANS THE PLAYLIST. Pressing play on the fourth row queues the WHOLE
 * playlist and starts at that row — which is what a running order is for, and what every
 * player does. It replaced a play/enqueue pair that both acted on the single track: "play
 * this one song" throws away the list you are looking at, and per-row enqueue is what the
 * hero's menu already does for the whole playlist. The tooltip says which of the two it is,
 * because "play" alone cannot.
 *
 * NOTHING HERE COSTS A REQUEST. Each row already IS a queue entry (PlaylistController sends
 * the whole playlist as one, since every entry of it is on screen anyway), so the button acts
 * on the objects in hand.
 *
 * THE WHOLE ROW IS THE LINK, which is why the markup looks the way it does. An <a> cannot
 * wrap the row — it holds a grip and a button, and an <a> may not contain interactive content
 * — so the title's anchor covers the row with a stretched `::after` instead, and the two real
 * controls are lifted back above that overlay. The playlists listing solves the same problem
 * the same way.
 *
 * It was the TITLE alone at first, and that was wrong in a way only the owner hovering it
 * found: the row lights up under the pointer along its whole width, so it promises a target
 * it did not have — everywhere but the words themselves there was no pointer cursor and
 * nothing to click. Either the promise or the glow had to go, and a listing whose rows are
 * clickable is what every other listing here does.
 *
 * The title therefore no longer underlines on hover: the row is already the target and
 * already says so, which is the same rule the Songs table's title cell follows. Only FOCUS
 * draws anything, and it draws a ring around the whole row rather than under the words —
 * the words are not what gets activated.
 *
 * REORDERING lives in usePlaylistTrackReorder, which owns the optimistic local order and the
 * PUT that persists it; this file supplies the grip, the keyboard handler and the classes.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";
import { usePlaylistTrackReorder } from "./usePlaylistTrackReorder";

/**
 * One entry of the playlist, as PlaylistController shaped it: a queue entry — so the row's
 * button can hand the playlist straight to the player — plus the two things a row shows and a
 * queue entry has no use for.
 */
export interface PlaylistTrackRow extends QueueTrack {
    /**
     * The PIVOT row's id, not the track's, and the key this list is rendered by: the same
     * track may sit in a playlist twice, so `id` is not unique down the list. It is also what
     * a reorder sends, since a track id would not identify a position.
     */
    entryId: string;
    /** The album's release year, or null for a loose file or an untagged rip. */
    year: number | null;
    /**
     * The file's own area-relative path — what "sort by path" sorts on, and the only reason
     * it is on the client at all. PlaylistController says why sending it is both cheap and
     * safe; usePlaylistTrackReorder::sortByPath is what uses it.
     */
    path: string;
}

const props = defineProps<{
    /** Which playlist these belong to — the reorder writes to its own endpoint. */
    playlistId: string;
    /**
     * The entries, already in the reader's own order (`playlist_tracks.position`). Rendered in
     * the order given — the ordering is the playlist itself, so re-sorting here would only let
     * the page and the data disagree.
     */
    tracks: PlaylistTrackRow[];
}>();

const { t } = useI18n();
const { playNow } = usePlayerQueue();
const { play } = usePlayerAudio();

/**
 * The list element, and the order the page actually renders.
 *
 * `entries` rather than the prop directly: a drag has to show before the server has agreed to
 * it, and an Inertia prop cannot be written. usePlaylistTrackReorder owns that copy, keeps it
 * seeded from the prop, and persists whatever the reader does to it.
 */
const list = useTemplateRef<HTMLUListElement>("list");
const { entries, onRowKeydown, shortcutLabel, sortByPath } = usePlaylistTrackReorder(
    list,
    () => props.tracks,
    () => props.playlistId
);

/**
 * Sorting is offered by a button in the HERO, which is a different component — so the page
 * reaches in and calls this rather than the list emitting upward.
 *
 * Deliberately that way round. The list owns the optimistic order (it has to: a drag must show
 * before the server agrees), and moving that state up to the page so the hero could reach it
 * would mean prop-drilling the order back down plus an event for every gesture. One imperative
 * call is the smaller seam, and it is the same shape a `<dialog>`'s `showModal()` is: the thing
 * that owns the state exposes a verb.
 */
defineExpose({ sortByPath });

/**
 * How long the track runs, as a clock. Empty for a file whose tags carried no duration, which
 * drops the chip rather than printing "0:00" — `formatClock` is null-in/null-out.
 */
const playingTime = (track: PlaylistTrackRow): string => formatClock(track.duration) ?? "";

/**
 * Queue the whole playlist and start at this row.
 *
 * The ENTRIES rather than the prop, so a drag that has not yet been acknowledged by the
 * server still plays in the order on screen — and the index is the row's place in that same
 * array, which is why both come from one source.
 *
 * `play()` is called explicitly, and it matters: loading a track does not start it, and a
 * browser only allows playback from a user gesture — this click is that gesture, so the call
 * has to happen inside the handler rather than in a watcher somewhere later. The same shape
 * SubjectMenu's own play uses.
 */
function playFrom(index: number): void {
    playNow(entries.value, index);
    play();
}
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, N items" before the rows, which is
         the one thing a bare stack of <div>s would say worse. -->
    <ul v-if="entries.length > 0" ref="list" class="playlist-tracks" :aria-label="t('playlists.detail.label')">
        <li
            v-for="(track, index) in entries"
            :key="track.entryId"
            class="playlist-tracks__item"
            @keydown="onRowKeydown($event, index)"
        >
            <!-- The reorder grip: the only thing a drag starts from, and the tab stop the
                 keyboard alternative hangs off. Pressing it does nothing on its own — the drag
                 is a pointer gesture and Alt+↑/↓ is the keyboard one — which is the honest
                 shape of a handle, and the same shape the playlists listing's and the play
                 queue's grips have. THE HINT SAYS "CLICK IT FIRST" because it has to: Alt+↑/↓
                 moves the FOCUSED row, so hovering one and pressing the keys does nothing at
                 all, which is exactly how it reads as broken. -->
            <button
                type="button"
                class="playlist-tracks__handle"
                v-tooltip="t('playlists.detail.reorderHint', { keys: shortcutLabel })"
                :aria-label="t('playlists.detail.reorder', { name: track.name })"
                aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown"
            >
                <icon name="drag" :size="1" />
            </button>

            <!-- The artwork, from `landscape` up — see the banner on what each width adds.
                 `xsmall` (32px) rather than the `small` every table row uses: here the picture
                 is the smallest thing in the row and must stay that way, since the row's height
                 is set by whatever is tallest in it — at 48px the artwork took over that job
                 and made the list half as tall again for a thumbnail nobody reads.
                 `decorative`: the track's name is the next thing in the row, and naming the
                 picture too would have a screen reader read every row twice. -->
            <span class="playlist-tracks__art">
                <cover-image :src="track.coverUrl" :title="track.name" size="xsmall" decorative />
            </span>

            <!-- The row's NAVIGATION, and the WHOLE ROW is its target: the anchor stretches a
                 `::after` over the row rather than wrapping it, since it may not contain the
                 grip or the button. So aiming at a fact chip, the artwork or the empty space
                 between them opens the song too. `prefetch` warms that page on hover, as
                 every other listing does. -->
            <Link :href="track.href" class="playlist-tracks__name" prefetch>{{ track.name }}</Link>

            <!-- One chip per fact, each dropped rather than shown empty when the tags don't
                 carry it: a file crediting nobody, one filed under no album, an untagged rip
                 with no year. Each also has its own breakpoint — see the styles. -->
            <span class="playlist-tracks__meta">
                <span v-if="track.artist" class="playlist-tracks__fact playlist-tracks__fact--artist">
                    <icon name="artist" :size="1" />{{ track.artist }}
                </span>
                <span v-if="track.album" class="playlist-tracks__fact playlist-tracks__fact--album">
                    <icon name="album" :size="1" />{{ track.album }}
                </span>
                <span v-if="playingTime(track)" class="playlist-tracks__fact playlist-tracks__fact--duration">
                    <icon name="duration" :size="1" />{{ playingTime(track) }}
                </span>
                <span v-if="track.year !== null" class="playlist-tracks__fact playlist-tracks__fact--year">
                    <icon name="calendar" :size="1" />{{ track.year }}
                </span>
            </span>

            <!-- Icon only. What it does is carried by the tooltip on hover and by `aria-label`
                 for everyone who never sees one — and both say "from here", because a bare play
                 triangle on a row reads as "play this one song" and this queues the list. -->
            <button
                type="button"
                class="playlist-tracks__play"
                v-tooltip="t('playlists.detail.playHint')"
                :aria-label="t('playlists.detail.play', { name: track.name })"
                @click="playFrom(index)"
            >
                <icon name="play" :size="1" />
            </button>
        </li>
    </ul>
    <p v-else>{{ t("playlists.detail.empty") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* A column of rows, at every width — see the banner for why this never becomes a grid. The UA
   marker and padding go (normalize.css leaves lists alone). */
.playlist-tracks {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-playlist-tracks, "gap");

    list-style: none;
}

/* ONE ENTRY. Grip, artwork, title, facts, play — the title takes the slack, so the facts and
   the button sit against the trailing edge and the buttons line up down the list as a column
   however long the titles run.

   `align-items: center` because the row is a single line in the overwhelming majority of
   cases, and where the facts wrap to a second line the controls still read as belonging to
   the row rather than to its first line.

   The frame is the genre page's artist card, re-picked from the globals (see the colour
   partial). */
.playlist-tracks__item {
    display: flex;
    position: relative; // positioning context for the stretched link and the hover glow
    align-items: center;
    flex-wrap: wrap;
    isolation: isolate; // keep the controls' rung contained to this row

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
       DataTable's clickable rows: the two-layer control-neon halo over a wash that only SHIFTS
       the row's existing fill. Both layers are soft and em-based — written as a hard ring plus
       a tight blur it reads as an outline drawn around the row rather than as the row lighting
       up.

       `:focus-within` as well as `:hover`, which the card one tab away does not need: this row
       holds real controls, so it can be reached by keyboard without a pointer ever touching
       it. The row is positioned at all times (see above), which is also what keeps this glow
       painting above its neighbours rather than under them. */
    &:hover,
    &:focus-within {
        background-color: map.get(c.$c-playlist-tracks, "hover-background");
        box-shadow:
            0 0 0.6em 0.1em map.get(c.$c-playlist-tracks, "glow"),
            0 0 1.5em 0.25em map.get(c.$c-playlist-tracks, "glow");
    }

    /* THE ROW IN YOUR HAND — the clone that follows the pointer during a drag (`dragClass`).
       Sortable builds it with `cloneNode`, so it keeps this component's scope attribute and
       every class; what it does not keep is its place on the page, because it is appended to
       <body> to stay clear of clipping ancestors. The fill and ink it was inheriting therefore
       have to be restated, or the row is drawn in whatever colours the page happens to use.
       The shadow is what says "lifted". */
    &--dragging {
        background-color: map.get(c.$c-playlist-tracks, "background");
        color: map.get(c.$c-playlist-tracks, "surface");
        box-shadow: 0 0.25em 0.75em 0 map.get(c.$c-playlist-tracks, "drag-shadow");

        cursor: grabbing;
    }

    /* THE GAP IT LEFT — the real <li>, still in the list, which Sortable moves around to show
       where a drop would land (`ghostClass`). Faded rather than hidden: collapsing it would
       make the list jump by a whole row the moment a drag started, and the point of the gap is
       that it shows the destination. */
    &--ghost {
        opacity: 0.4;

        background-color: map.get(c.$c-playlist-tracks, "hover-background");
    }
}

/* …and the two real controls are lifted back above the overlay. A POSITION IS REQUIRED, not
   just a z-index: the overlay is positioned, so it paints above every non-positioned
   descendant of the same stacking context regardless of DOM order, and without this it would
   silently swallow both — the row would navigate while neither control could be pressed.
   Exactly the trap the playlists listing and the play queue's row both document. */
.playlist-tracks__handle,
.playlist-tracks__play {
    position: relative;
    z-index: z.$c-playlist-tracks;
}

/* THE REORDER GRIP, leading the row at every width. `grab` is the whole reason the cursor is
   declared here rather than inherited: the rest of the row opens the song and says `pointer`,
   this strip moves it. The padding is what makes it findable — an icon with nothing beside it
   is a 19px target otherwise, which is the problem the play queue's grip solved by widening
   its own strip.

   A wash even at rest, so the grab area is VISIBLE rather than implied by a floating glyph;
   the same call the listing's handle makes, and deliberately weaker than the active fill so
   the two read as one surface waking up. */
.playlist-tracks__handle {
    display: grid;
    place-items: center;

    flex: 0 0 auto;

    padding: map.get(s.$c-playlist-tracks, "control-padding");
    border: 0;

    background-color: map.get(c.$c-playlist-tracks, "control-background");
    color: map.get(c.$c-playlist-tracks, "control");

    border-radius: map.get(s.$c-playlist-tracks, "control-radius");

    cursor: grab;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            color ti.$c-playlist-tracks linear,
            background-color ti.$c-playlist-tracks linear;
    }

    &:active {
        cursor: grabbing;
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

/* The artwork, from `landscape` up. Below that the row has room for a title and its two
   controls, and a thumbnail in front of them costs the title a chunk of a phone's width for a
   picture too small to recognise.

   `display: flex` kills the inline baseline gap under an <img>; `display: none` rather than
   `visibility` so the row loses the box entirely instead of keeping an empty one — a hidden
   lazy image is never fetched either, so the narrow layout costs no request. */
.playlist-tracks__art {
    display: none;

    flex: 0 0 auto;

    @include m.mq("landscape") {
        display: flex;
    }
}

/* The title, and the row's link to the song. Takes the slack, so the facts and the controls
   sit against the trailing edge. `min-width: 0` plus `overflow-wrap: anywhere` is what keeps
   a long unbreakable title — a German compound, a filename-as-title from an untagged rip —
   wrapping inside the row instead of pushing the button off its edge. The same flex trap the
   player bar's meta column and the queue row both document.

   IT DOES NOT LOOK LIKE A LINK, and no longer underlines on hover: the whole row is the
   target and the row's own glow already says so, which is the rule every listing here follows
   (see the Songs table's title cell). An underline would also be pointing at the wrong thing
   — the words are not what gets activated.

   THE OVERLAY IS WHAT MAKES THE ROW CLICKABLE. An <a> cannot wrap this row, holding as it does
   a grip and a button, so it stretches a positioned `::after` over it instead. `inset: 0`
   resolves against `.playlist-tracks__item`, which is what its `position: relative` is for;
   the radius matches so the focus ring traces the row's rounded corners rather than a
   rectangle around them. Everything else in the row stays UNDER this overlay on purpose, so
   aiming at a fact chip or the artwork opens the song; the two real controls are lifted back
   above it below. */
.playlist-tracks__name {
    overflow-wrap: anywhere;
    min-width: 0;
    flex: 1 1 auto;

    color: inherit;

    font-weight: bold;
    text-decoration: none;

    &::after {
        position: absolute;
        inset: 0;

        border-radius: map.get(s.$c-playlist-tracks, "radius");

        content: "";
    }

    /* The focus ring goes on the OVERLAY, not the anchor: the anchor is only as wide as its
       title, so a ring on it would trace the words while the thing being activated is the
       whole row. `:focus-visible` so a pointer click doesn't draw one. */
    &:focus-visible {
        outline: 0;

        &::after {
            outline: 2px solid currentcolor;
            outline-offset: -2px;
        }
    }
}

/* GONE below `portrait`, container and all. Hiding only the chips would leave an empty flex
   item behind, and the row's `gap` applies either side of it — so the phone layout would carry
   a stray gap between the title and the play button for facts nobody can see. */
.playlist-tracks__meta {
    display: none;

    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;

    gap: map.get(s.$c-playlist-tracks, "meta-gap");

    color: map.get(c.$c-playlist-tracks, "surface-meta");

    @include m.mq("portrait") {
        display: flex;
    }
}

/* Each fact as its own chip — the same object the artist card's numbers and the Discography's
   facts are, with the icon that names it so a value reads without its label. `tabular-nums` so
   the years and clocks line up down the list without also monospacing the artist and album
   beside them. */
.playlist-tracks__fact {
    display: none;

    align-items: center;

    padding: map.get(s.$c-playlist-tracks, "meta-padding");
    gap: map.get(s.$c-playlist-tracks, "fact-icon-gap");

    background-color: map.get(c.$c-playlist-tracks, "meta-background");

    border-radius: map.get(s.$c-playlist-tracks, "meta-radius");

    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

/* ONE FACT PER BREAKPOINT, in order of how much it tells you apart from the title (owner's
   call). Each is `inline-flex` rather than `flex` so the chip is sized by its content and the
   row of them wraps as text does.

   Declared as four rules rather than one loop because the pairing is a decision per fact, not
   a sequence: which fact earns the next slice of width is exactly the thing being chosen here,
   and a loop over a list would hide it. */
.playlist-tracks__fact--artist {
    @include m.mq("portrait") {
        display: inline-flex;
    }
}

.playlist-tracks__fact--album {
    @include m.mq("landscape") {
        display: inline-flex;
    }
}

.playlist-tracks__fact--duration {
    @include m.mq("desktop") {
        display: inline-flex;
    }
}

.playlist-tracks__fact--year {
    @include m.mq("full") {
        display: inline-flex;
    }
}

/* The play button, holding the trailing edge. `margin-inline-start: auto` is what keeps it
   there at every width, and it is not redundant with the title's `flex: 1` — that only absorbs
   the slack while everything shares one line; where the facts fill the line and the button
   wraps onto its own, it is the only item there and would otherwise sit at its leading edge,
   under the title.

   A SUBTLE FILL AT REST rather than a bare glyph (owner's call), so the target is visible
   rather than implied — the same wash the grip carries, and the same argument the listing's
   handle makes. It lights to the control neon under the pointer or on focus, which is also
   where the row's own halo appears: one moment, two signals, saying "this row is live". */
.playlist-tracks__play {
    display: inline-flex;
    align-items: center;

    flex: 0 0 auto;
    padding: map.get(s.$c-playlist-tracks, "control-padding");
    border: 0;
    margin-inline-start: auto;

    background-color: map.get(c.$c-playlist-tracks, "control-background");
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

    /* `:focus-visible` rather than `:focus`, so a pointer click doesn't leave the button lit
       after the pointer has gone. The outline is what a keyboard user gets on top of the wash,
       since the row's own halo says "somewhere in here" and cannot say which control. */
    &:focus-visible {
        outline: 2px solid currentcolor;
    }
}
</style>
