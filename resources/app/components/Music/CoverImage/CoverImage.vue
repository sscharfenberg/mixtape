<script setup lang="ts">
/******************************************************************************
 * CoverImage
 * Album / track artwork, at one of four sizes. The single place the app decides
 * what a cover looks like and how it behaves when there isn't one — before this,
 * every listing and hero carried its own copy of the same <img>, the same frame,
 * the same placeholder glyph and the same load-failure guard.
 *
 * It owns THREE things a consumer used to repeat:
 *
 * 1. The size triple. A size is not just a width: the corner radius and frame
 *    width move with it, because the 12px rounding that reads as deliberate on a
 *    240px sleeve eats a bite out of a 48px thumbnail, and the hero's 5px frame
 *    around a 48px square would be a tenth of the picture (s.$c-cover-image).
 * 2. The MISSING case. `src` of null means the file carries no art, and the
 *    component draws a muted glyph instead — deliberately just the glyph, with no
 *    frame of its own: inside a HeroSection the hero already draws its dashed
 *    square around whatever is not an `<img>`, and in a table row the bare icon is
 *    the whole signal.
 * 3. The FAILED case, which is the one worth having a component for. `coverUrl`
 *    rests on `tracks.cover` / `collections.cover_path` — scan-time flags — so a
 *    file re-tagged or deleted since the last `app:update` is still advertised and
 *    then 404s. Each consumer used to keep a `failedCovers` Set keyed by row id and
 *    an `@error` handler to maintain it, purely because the <img> lived inside a
 *    `v-for`. An instance per row already has that identity, so the state is a
 *    single boolean here and the Sets are gone.
 *
 * `alt` follows the rule the call sites were already applying by hand: artwork
 * beside its own title is DECORATIVE, because naming it again makes a screen
 * reader read every row twice. Hence `decorative`, which every table row passes and
 * a hero does not.
 *****************************************************************************/
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

/**
 * The sizes artwork is shown at. The three small ones are fixed, because they sit in rows
 * whose height should not move; `xlarge` is the page anchor and is responsive — it fills
 * its container up to a ceiling.
 */
export type CoverSize = "tiny" | "small" | "large" | "xlarge";

const props = withDefaults(
    defineProps<{
        /**
         * The artwork's URL, or null when the file carries none — which draws the
         * placeholder glyph rather than pointing an `<img>` at a 404.
         */
        src?: string | null;
        /**
         * What the picture is of — an album or song title. Used as the alt text, so it
         * should be the name a reader would expect to hear, not "cover of …" (a screen
         * reader already says "image"). Ignored when `decorative`.
         */
        title: string;
        /**
         * How big to draw it: tiny 24px, small 48px, large 96px — or `xlarge`, which fills
         * its container and stops at 240px, so the page-anchor cover adapts on its own.
         */
        size?: CoverSize;
        /**
         * Render the image as decorative (`alt=""`, placeholder hidden from assistive
         * tech). Pass it wherever the title is already adjacent — a table row, a card
         * heading, a link that names the album — so the name is not announced twice.
         */
        decorative?: boolean;
    }>(),
    { src: null, size: "small", decorative: false }
);

const { t } = useI18n();

/**
 * Whether this instance's `<img>` failed to load, which swaps it to the placeholder.
 *
 * Per-instance state, which is the whole point: the consumers this replaces each kept a
 * Set of failed row ids because one shared handler served a whole `v-for`.
 */
const failed = ref(false);

// Reset when the URL changes: Vue reuses a component instance when a keyed list re-orders
// or re-renders, so without this a row that once 404'd would keep showing the placeholder
// after being handed a different album's artwork.
watch(
    () => props.src,
    () => {
        failed.value = false;
    }
);

/** The glyph size that sits comfortably inside each cover size. */
const ICON_SIZE: Record<CoverSize, number> = { tiny: 1, small: 2, large: 3, xlarge: 5 };
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        :alt="decorative ? '' : title"
        :class="`cover-image cover-image--${size}`"
        loading="lazy"
        @error="failed = true"
    />
    <icon
        v-else
        name="music"
        :size="ICON_SIZE[size]"
        class="cover-image__placeholder"
        :role="decorative ? undefined : 'img'"
        :aria-label="decorative ? undefined : t('components.cover.empty')"
        :aria-hidden="decorative ? 'true' : undefined"
    />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* `display: block` and `border-box` are both load-bearing, and both were bugs found the
   hard way in the listings this replaces: an inline <img> carries a baseline gap that
   makes its row taller than the picture, and without border-box the frame is added
   OUTSIDE the declared width — which at hero size pushes the whole panel wider. */
.cover-image {
    display: block;

    box-sizing: border-box;

    border: map.get(s.$c-cover-image, "border") solid map.get(c.$c-cover-image, "border");

    border-radius: map.get(s.$c-cover-image, "radius");

    /* Covers are square; a non-square scan is cropped rather than distorted. */
    object-fit: cover;

    &--tiny {
        width: map.get(s.$c-cover-image, "tiny");
        height: map.get(s.$c-cover-image, "tiny");
    }

    &--small {
        width: map.get(s.$c-cover-image, "small");
        height: map.get(s.$c-cover-image, "small");
    }

    &--large {
        width: map.get(s.$c-cover-image, "large");
        height: map.get(s.$c-cover-image, "large");
    }

    /* The page anchor: a heavier frame, a wider rounding, and RESPONSIVE by default —
       it takes the width of whatever it is placed in and simply stops growing at the
       token's ceiling. So it fills the hero's frame at every breakpoint without this
       file knowing that frame's sizes, and it fits any narrower container it is ever
       dropped into instead of overflowing it.

       `aspect-ratio` rather than a matching height, because there is no height to
       match once the width is inherited — it is what keeps the box square while only
       one of its dimensions is known. `height: auto` is the default for an <img> but
       is stated so a later rule can't quietly break the ratio. */
    &--xlarge {
        width: 100%;
        max-width: map.get(s.$c-cover-image, "xlarge");
        height: auto;
        border-width: map.get(s.$c-cover-image, "border-xlarge");
        aspect-ratio: 1;

        border-radius: map.get(s.$c-cover-image, "radius-xlarge");
    }
}

/* No frame and no box — see the component banner: the hero draws its own dashed square
   around this, and in a table row the bare glyph is the whole signal. */
.cover-image__placeholder {
    color: map.get(c.$c-cover-image, "placeholder");
}
</style>
