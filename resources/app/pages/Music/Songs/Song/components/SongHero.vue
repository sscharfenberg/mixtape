<script setup lang="ts">
/******************************************************************************
 * SongHero
 * The song page's first row: cover art, the title with its artist / album /
 * year line, and the two big actions (play, add to queue).
 *
 * Built out of one Widget inside a WidgetGroup rather than a card of its own, so
 * it sits on exactly the same surface as the facts cards below it and as the
 * browse-page widgets — the hero is the page's loudest element by SIZE (a 220px
 * cover and two neon buttons), not by inventing a second card style. The Widget
 * needs a WidgetGroup around it because it subgrids into the group's row bands.
 *
 * The cover is loaded from SongCoverController via `song.coverUrl`, which the
 * controller sets to null when the file has no cover at all — that case renders a
 * neon-outlined placeholder instead, because a missing cover is normal in a
 * ripped collection and a broken <img> would read as a bug.
 *
 * Play / queue are DELIBERATELY inert for now: there is no player and no queue in
 * v2 yet (docs/app-rewrite.md), and the buttons exist so the hero's final layout
 * is settled before the player lands rather than being re-cut around it. They say
 * so when clicked.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { SongDetail } from "Types/music";

const props = defineProps<{
    /** The song being shown, as SongController shaped it — every value raw. */
    song: SongDetail;
}>();

const { t } = useI18n();

/**
 * The line under the title: artist · album · year, with whatever is untagged left
 * out entirely (so a lone artist never renders as "The Storm · · "). Middle dots
 * rather than a comma list because the three parts are peers, not a sentence.
 */
const credits = computed(() =>
    [props.song.artist, props.song.album, props.song.year === null ? null : String(props.song.year)]
        .filter(part => part !== null && part !== "")
        .join(" · ")
);

/**
 * Alt text for the cover: the album it belongs to, or the song when the file is
 * filed under no album. Not "cover of …" — a screen reader already says "image".
 */
const coverAlt = computed(() => props.song.album ?? props.song.name);

/**
 * Placeholder for the actions until there is something to act on. An alert, not a
 * toast, on purpose: it is meant to be unmistakably temporary scaffolding, so
 * nobody mistakes it for a finished no-op button.
 */
const soon = (): void => {
    alert("soon");
};
</script>

<template>
    <widget-group>
        <widget>
            <div class="song-hero">
                <img v-if="song.coverUrl" class="song-hero__cover" :src="song.coverUrl" :alt="coverAlt" />
                <div v-else class="song-hero__cover song-hero__cover--empty" role="img" :aria-label="t('music.song.noCover')">
                    <icon name="album" :size="5" />
                </div>

                <div class="song-hero__meta">
                    <h1 class="song-hero__title">{{ song.name }}</h1>
                    <p v-if="credits" class="song-hero__credits">{{ credits }}</p>

                    <div class="song-hero__actions">
                        <Button variant="primary" @click="soon">
                            <icon name="play" :size="2" />
                            {{ t("music.song.play") }}
                        </Button>
                        <Button @click="soon">
                            <icon name="enqueue" :size="2" />
                            {{ t("music.song.enqueue") }}
                        </Button>
                    </div>
                </div>
            </div>
        </widget>
    </widget-group>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* Stacked on a phone (cover, then title, then buttons — the reading order),
   side-by-side from `portrait` up, where a square cover plus a title line fit
   across without either being squeezed.

   The meta column deliberately STRETCHES to the cover's height (the grid default)
   rather than hugging its content: that lets the actions sit at the bottom of the
   column, level with the cover's bottom edge, so the row reads as one block. It
   also puts the buttons' neon floor-reflection (.btn::before — a wide blurred
   trapezoid) over the card's bottom padding, where the card clips it, instead of
   smearing it across the empty middle of the hero. */
.song-hero {
    display: grid;

    gap: map.get(s.$p-song, "hero-gap");

    @include m.mq("portrait") {
        grid-template-columns: auto 1fr;
    }
}

/* The cover: a fixed square that grows a step at the wider breakpoints. Capped at
   100% so the phone layout can't push the card wider than the viewport, and
   `object-fit: cover` keeps a non-square scan from distorting. The neon halo is
   the same two-layer glow the DataTable's hovered row and the open popover use —
   it is the app's "this is the lit thing here" gesture, and the cover is it. */
.song-hero__cover {
    width: map.get(s.$p-song, "cover", "base");
    max-width: 100%;
    aspect-ratio: 1;

    border-radius: map.get(s.$p-song, "cover-radius");

    /* Kept deliberately tight (rather than the row-hover's 1.5em spread): the
       Widget clips to its rounded corners, so a wider halo would be cut off
       square at the card's padding edge instead of fading out. */
    box-shadow:
        0 0 0.4em 0.05em map.get(c.$p-song, "cover-glow"),
        0 0 1em 0.1em map.get(c.$p-song, "cover-glow");
    object-fit: cover;

    @include m.mq("landscape") {
        width: map.get(s.$p-song, "cover", "landscape");
    }

    @include m.mq("desktop") {
        width: map.get(s.$p-song, "cover", "desktop");
    }
}

/* No cover on disk: the same square, drawn as a dashed neon outline around a
   muted album icon, so the hero keeps its shape and the gap is legible as
   "nothing here" rather than as a failed image. */
.song-hero__cover--empty {
    display: grid;
    place-items: center;

    border: map.get(s.$p-song, "cover-placeholder-border") dashed map.get(c.$p-song, "cover-placeholder-border");

    background-color: map.get(c.$p-song, "cover-placeholder-background");
    color: map.get(c.$p-song, "cover-placeholder-icon");
    box-shadow: none;
}

.song-hero__meta {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$p-song, "hero-meta-gap");
}

/* The page's h1. Bigger than body text and tight-leaded so a long title wraps
   into a block rather than a ladder; `overflow-wrap` because track titles do
   contain single unbroken monsters (a URL, a 40-character German compound). */
.song-hero__title {
    overflow-wrap: anywhere;
    margin: 0;

    font-family: map.get(t.$p-song, "title");
    font-size: map.get(s.$p-song, "title-font-size");
    line-height: 1.1;
}

.song-hero__credits {
    margin: 0;

    color: map.get(c.$p-song, "credits");
}

/* Wraps, because two buttons with German labels don't fit a phone's width side
   by side; `margin-top: auto` pins them to the bottom of the meta column when the
   cover is the taller of the two. */
.song-hero__actions {
    display: flex;
    flex-wrap: wrap;

    margin-top: auto;
    gap: map.get(s.$p-song, "hero-meta-gap");
}
</style>
