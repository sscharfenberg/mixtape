<script setup lang="ts">
/******************************************************************************
 * NeighbourTrack
 * One of the two tracks either side of what is playing, as it appears in the Now Playing page's
 * third row: the artwork, the title, and the same facts the hero above carries — artist, album,
 * genre, runtime.
 *
 * IT IS ALSO THE BUTTON. The whole card steps the player to that track, because a card labelled
 * "next" beside a separate arrow is two controls for one intention — and on a phone the card is
 * the thing under your thumb. The heading and the facts sit inside a real `<button>` whose
 * accessible name says which direction and which track, so a screen reader gets "next track,
 * Paranoid Android" rather than a wall of unlabelled facts.
 *
 * IT RENDERS WITH NO TRACK, on purpose. At the ends of a queue there is no previous or no next,
 * and a card that vanished would move the queue below it up and down as playback advances — a
 * layout that shifts under a reader for reasons they cannot see. So the slot keeps its place and
 * says there is nothing there, with the button disabled.
 *
 * UNDER SHUFFLE BOTH DIRECTIONS ARE REAL ANSWERS rather than the rows above and below: `next` is
 * the row the queue has already drawn (docs/play-queue.md → the pre-draw) and `previous` is the
 * track actually HEARD before this one. Nothing here has to know that — `usePlayerQueue` answers
 * both — but it is the reason this card can exist at all.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

const props = defineProps<{
    /** Which side of the loaded track this is — decides the label, the glyph and the order. */
    direction: "previous" | "next";
    /** The track, or null at the end of the queue in that direction. */
    track: QueueTrack | null;
    /** Its genre, from the page's `genres` map. Null when untagged or not yet fetched. */
    genre: string | null;
}>();

const emit = defineEmits<{ step: [] }>();

const { t } = useI18n();

/** "Vorheriger Titel" / "Nächster Titel" — the card's own heading, and half its accessible name. */
const label = computed(() => t(`nowPlaying.${props.direction}`));

/**
 * What the button announces: the direction, and the track it would move to.
 *
 * Both halves matter. The direction alone repeats on two cards; the title alone gives no clue
 * which way pressing it goes.
 */
const action = computed(() =>
    props.track === null
        ? t(`nowPlaying.no.${props.direction}`)
        : t("nowPlaying.stepTo", { direction: label.value, name: props.track.name })
);

/** The facts under the title, in the hero's order, with the ones this track has no answer for dropped. */
const facts = computed(() =>
    props.track === null
        ? []
        : [
              { key: "artist", icon: "artist", value: props.track.artist },
              { key: "album", icon: "album", value: props.track.album },
              { key: "genre", icon: "genre", value: props.genre },
              { key: "duration", icon: "duration", value: formatClock(props.track.duration) }
          ].filter(fact => fact.value)
);
</script>

<template>
    <button
        type="button"
        class="neighbour"
        :class="[`neighbour--${direction}`, { 'neighbour--empty': track === null }]"
        :disabled="track === null"
        :aria-label="action"
        @click="emit('step')"
    >
        <span class="neighbour__label">
            <!-- The transport's own glyphs, rather than a new pair: the player bar's skip
                 buttons are `first-page` / `last-page`, and a card that steps the queue should
                 look like the control that does the same thing. It also keeps this change off
                 the sprite, which is gitignored and would need `npm run icons` on deploy. -->
            <icon :name="direction === 'previous' ? 'first-page' : 'last-page'" :size="1" />
            {{ label }}
        </span>

        <span v-if="track" class="neighbour__body">
            <!-- `decorative`: the title is right beside it, so naming the art again makes a
                 screen reader read every card twice.

                 `large` (96px), a rung up from the listing thumbnail: at a card this wide the
                 48px square read as a favicon beside its own title, and these are two of only
                 three covers on the whole page. -->
            <cover-image :src="track.coverUrl" :title="track.name" size="large" decorative />
            <span class="neighbour__meta">
                <span class="neighbour__title">{{ track.name }}</span>
                <!-- A WRAPPING ROW OF CHIPS rather than a stack of lines: a card holds four facts
                     of wildly different lengths (a two-character year beside a long album title),
                     and one per line left most of the card empty while the longest still
                     ellipsised. Wrapped, they take exactly the room they need. -->
                <span class="neighbour__facts">
                    <span v-for="fact in facts" :key="fact.key" class="neighbour__fact">
                        <icon :name="fact.icon" :size="1" />
                        {{ fact.value }}
                    </span>
                </span>
            </span>
        </span>
        <span v-else class="neighbour__body neighbour__body--empty">{{ t(`nowPlaying.no.${direction}`) }}</span>
    </button>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* A card that is a button: the whole surface steps the player, so it takes the Card's own fill,
   border and radius rather than looking like a control. Re-picked from the globals per the token
   rules — a component consumes contextual tokens, and these are the card's.

   `text-align: left` because a button centres its content by default and this one is a card of
   prose. */
.neighbour {
    display: flex;
    flex-direction: column;

    width: 100%;
    padding: map.get(s.$c-card, "padding");
    border: map.get(s.$c-card, "border") solid map.get(c.$c-card, "border");

    gap: map.get(s.$c-neighbour-track, "gap");

    background-color: map.get(c.$c-card, "background");
    color: map.get(c.$c-card, "surface");
    border-radius: map.get(s.$c-card, "radius");

    text-align: left;

    &:not(:disabled) {
        cursor: pointer;
    }

    @media (prefers-reduced-motion: no-preference) {
        transition:
            border-color ti.$c-neighbour-track linear,
            background-color ti.$c-neighbour-track linear;
    }

    &:not(:disabled):hover,
    &:not(:disabled):focus-visible {
        border-color: map.get(c.$c-neighbour-track, "edge-active");
    }
}

/* Which direction this is. Quiet and small — it labels the card, it is not the content. */
.neighbour__label {
    display: flex;
    align-items: center;

    gap: 0.5ch;

    color: map.get(c.$c-neighbour-track, "muted");

    font-size: map.get(s.$c-neighbour-track, "font-size");
    text-transform: uppercase;
}

.neighbour__body {
    display: flex;
    align-items: center;

    min-width: 0;

    gap: map.get(s.$c-neighbour-track, "gap");
}

/* Nothing in this direction — the card keeps its place so the page below it does not move as
   playback advances. */
.neighbour__body--empty {
    color: map.get(c.$c-neighbour-track, "muted");
}

/* `min-width: 0` so a long title ellipsises instead of pushing the card wider — the flex trap the
   player bar's meta column and the queue row both document. */
.neighbour__meta {
    display: flex;
    flex-direction: column;

    overflow: hidden;
    min-width: 0;

    gap: map.get(s.$c-neighbour-track, "chip-gap");
}

.neighbour__title {
    overflow: hidden;

    font-weight: 700;

    white-space: nowrap;
    text-overflow: ellipsis;
}

/* The facts wrap rather than stacking — see the template for why. */
.neighbour__facts {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-neighbour-track, "chip-gap");
}

/* One fact, on a subtle fill so the row reads as a set of small things rather than as a paragraph
   with icons in it. Each is dropped when the file has no answer for it, so an untagged rip shows a
   title and nothing else rather than a row of empty chips.

   `nowrap` on the chip, not on the row: a chip never breaks mid-fact, and the ROW is what wraps. */
.neighbour__fact {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-neighbour-track, "chip-padding");

    gap: 0.5ch;

    background-color: map.get(c.$c-neighbour-track, "chip");
    color: map.get(c.$c-neighbour-track, "muted");
    border-radius: map.get(s.$c-neighbour-track, "chip-radius");

    font-size: map.get(s.$c-neighbour-track, "font-size");
    white-space: nowrap;
}
</style>
