<script setup lang="ts">
/******************************************************************************
 * SongPageHero
 * The song page's first row: cover art beside the title and its artist / album /
 * year line.
 *
 * Its own panel rather than a Widget (the browse pages' card): the same card fill,
 * but framed by a rotating four-hue gradient ring instead of a flat border and a
 * gradient title strip. Same surface as the facts cards below, one step louder at
 * the edge — which is the hero's job on a page whose other blocks are all lists.
 *
 * The cover is loaded from SongCoverController via `song.coverUrl`, which the
 * controller sets to null when the file has no cover at all — that case renders a
 * neon-outlined placeholder instead, because a missing cover is normal in a
 * ripped collection and a broken <img> would read as a bug.
 *
 * There are no play / queue actions here yet. They were mocked up as inert
 * buttons while the layout was being settled; the mock-up is gone, and the real
 * controls arrive with the player itself (docs/app-rewrite.md) rather than sitting
 * here doing nothing in the meantime.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
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
</script>

<template>
    <div class="song-hero">
        <img v-if="song.coverUrl" class="song-hero__cover" :src="song.coverUrl" :alt="coverAlt" />
        <div v-else class="song-hero__cover song-hero__cover--empty" role="img" :aria-label="t('music.song.noCover')">
            <icon name="album" :size="5" />
        </div>

        <div class="song-hero__meta">
            <h1 class="song-hero__title">{{ song.name }}</h1>
            <p v-if="credits" class="song-hero__credits">{{ credits }}</p>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:list"; // https://sass-lang.com/documentation/modules/list
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;
@use "Abstracts/typography" as t;

/* The border gradient's angle, as a REGISTERED custom property — the registration
   is what makes the rotation possible at all: an unregistered `--angle` is an
   untyped token to the animation engine, so the gradient would jump between
   keyframes instead of sweeping. Typed as `<angle>`, it interpolates. Same
   mechanism as `.glowing-border`'s `--glowing-deg` (styles/components/
   _glowing-border.scss), down to the motion guard below.

   `@property` is a global at-rule — Vue's scoped transform only rewrites selectors
   — so the name is component-prefixed to keep the app-wide registry collision-free. */
@property --song-hero-border-angle {
    syntax: "<angle>";
    inherits: false;
    initial-value: 135deg;
}

/* Exactly one turn, so the loop restarts on the frame it ended: any start angle
   works as long as the end is start + 360deg. */
@keyframes song-hero-border-rotate {
    to {
        --song-hero-border-angle: 495deg;
    }
}

/* Stacked on a phone (cover, then title — the reading order), side-by-side from
   `portrait` up, where a square cover plus a title line fit across without either
   being squeezed.

   The meta column is CENTRED against the cover rather than stretched to its
   height: with the actions gone it holds two short lines next to a ~240px square,
   and top-aligning them leaves a tall well of empty space under the text that
   reads as a missing element. Centred, the two halves of the row balance. */
.song-hero {
    display: grid;
    position: relative; // positioning context for the border ring below
    align-items: center;
    isolation: isolate; // keep the ring's rung contained to this panel

    padding: map.get(s.$p-song, "hero-padding");
    gap: map.get(s.$p-song, "hero-gap");

    background-color: map.get(c.$p-song, "hero-background");
    border-radius: map.get(s.$p-song, "hero-radius");

    @include m.mq("portrait") {
        grid-template-columns: auto 1fr;
    }

    /* The featured border, drawn as a gradient ring: fill the ::before with the hue
       ramp over the border-box, then mask the padding-box back out so only the ring
       survives — a plain `border` can only take one flat colour. Same technique as
       the shared `.frosted-glass`, but owned here: this frame is the page's own
       decision, and it sits over the panel's own opaque fill rather than glass.

       CONIC, not linear, and that is what makes the rotation even. A linear
       gradient's line is projected onto the box, so its length is
       |W·sin(a)| + |H·cos(a)| — on a panel this much wider than it is tall, that
       swings by a factor of five as the angle turns, and the bands visibly crawl
       near the horizontal and race near the vertical even though the angle itself
       changes at a constant rate. A conic gradient sweeps around a centre point, so
       equal angle equals equal sweep the whole way round. `.glowing-border` uses one
       for the same reason.

       The ramp's stops come from the token as a list; the first is repeated as the
       last so 360deg meets 0deg on the same colour instead of a hard seam.
       `border-radius: inherit` makes the ring follow the panel's corners, and it
       paints on the "raised" rung so it reads unbroken even where the cover square
       meets it. */
    &::before {
        $ramp: map.get(c.$p-song, "hero-border-ramp");

        position: absolute;
        inset: 0;
        z-index: z.$p-song;

        border: map.get(s.$p-song, "hero-border") solid transparent;

        background: conic-gradient(from var(--song-hero-border-angle), #{$ramp}, #{list.nth($ramp, 1)})
            border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";

        pointer-events: none;

        /* Ambient, continuous motion, so it is opt-in behind `no-preference` like
           every other animation here — with the preference set (or unknown) the ring
           just holds the 135deg the property was registered with, which is the
           `to bottom right` it had before it moved. */
        @media (prefers-reduced-motion: no-preference) {
            animation: song-hero-border-rotate ti.$p-song linear infinite;
        }
    }
}

/* The cover: a fixed square that grows a step at the wider breakpoints. Capped at
   100% so the stacked phone layout can't push it past the Container's inline
   padding, and `object-fit: cover` keeps a non-square scan from distorting. No
   glow — the artwork is already the most saturated thing on the page, and a neon
   halo behind it only muddied its own edge. */
.song-hero__cover {
    width: map.get(s.$p-song, "cover", "base");
    max-width: 100%;
    aspect-ratio: 1;

    border-radius: map.get(s.$p-song, "cover-radius");

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
</style>
