<script setup lang="ts">
/******************************************************************************
 * CoverImage
 * Album / track artwork, at one of five sizes. The single place the app decides
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
 *    component draws a muted glyph instead — deliberately with no FRAME of its own:
 *    inside a HeroSection the hero already draws its dashed square around whatever
 *    is not an `<img>`, and in a table row the bare icon is the whole signal. It
 *    does hold the same BOX as the image at the four fixed sizes, though, which is
 *    a different thing: a music note is far smaller than the cover it stands in
 *    for, so without it a list mixing tagged and untagged files got rows of two
 *    heights, and a flex row laying out cover-then-text (the play queue, the player
 *    bar) started its text in a different place per row.
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
 * The sizes artwork is shown at. The four small ones are fixed, because they sit in rows
 * whose height should not move; `xlarge` is the page anchor and has no width of its own —
 * it fills whatever container it is given.
 *
 * The order is tiny (24) < xsmall (32) < small (48) < large (96), which the names do not
 * admit: `tiny` was named first and kept its name rather than every consumer being moved
 * to make room. See the size token's own note.
 */
export type CoverSize = "tiny" | "xsmall" | "small" | "large" | "xlarge";

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
         * How big to draw it: tiny 24px, xsmall 32px, small 48px, large 96px — or `xlarge`,
         * which has no width of its own and fills its container, so the page-anchor cover
         * adapts on its own and the CONTAINER is what bounds it.
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

/**
 * The glyph size that sits comfortably inside each cover size.
 *
 * `xsmall` shares `tiny`'s step rather than taking the next one up: the glyph at step 2 is
 * 24px, which in a 32px box leaves four pixels of margin and reads as a note crammed into a
 * square instead of standing in for a picture.
 */
const ICON_SIZE: Record<CoverSize, number> = { tiny: 1, xsmall: 1, small: 2, large: 3, xlarge: 5 };
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
    <span v-else :class="['cover-image__box', `cover-image__box--${size}`]">
        <icon
            name="music"
            :size="ICON_SIZE[size]"
            class="cover-image__placeholder"
            :role="decorative ? undefined : 'img'"
            :aria-label="decorative ? undefined : t('components.cover.empty')"
            :aria-hidden="decorative ? 'true' : undefined"
        />
    </span>
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

    &--xsmall {
        width: map.get(s.$c-cover-image, "xsmall");
        height: map.get(s.$c-cover-image, "xsmall");
    }

    &--small {
        width: map.get(s.$c-cover-image, "small");
        height: map.get(s.$c-cover-image, "small");
    }

    &--large {
        width: map.get(s.$c-cover-image, "large");
        height: map.get(s.$c-cover-image, "large");
    }

    /* The page anchor: a heavier frame, a wider rounding, and no width of its own — it
       fills whatever it is placed in, always. THE CONTAINER DECIDES THE SIZE, which is
       the whole contract: the hero's frame is 220/200/240 per breakpoint and this fills
       it without knowing those numbers, and a card in a grid column gets a cover that
       spans the card whatever the column works out to.

       It deliberately carries no ceiling. One used to live here, and it was wrong in
       exactly the case a fluid grid produces: any column wider than the cap left the
       cover short of its own card. A caller that needs a bound puts it on the container,
       where the rest of that layout's sizing already lives.

       `aspect-ratio` rather than a matching height, because there is no height to match
       once the width is inherited — it is what keeps the box square while only one of
       its dimensions is known. `height: auto` is the default for an <img> but is stated
       so a later rule can't quietly break the ratio. */
    &--xlarge {
        width: 100%;
        height: auto;
        border-width: map.get(s.$c-cover-image, "border-xlarge");
        aspect-ratio: 1;

        border-radius: map.get(s.$c-cover-image, "radius-xlarge");
    }
}

/* No FRAME — see the component banner: the hero draws its own dashed square around
   this, and in a table row a bordered box would read as artwork that failed rather
   than artwork that was never there. */
.cover-image__placeholder {
    color: map.get(c.$c-cover-image, "placeholder");
}

/* The box the placeholder sits in, so a track with no artwork occupies the same
   square as one with it.

   Its rationale lives here rather than in the template beside it, and that is not a
   preference: a template comment is a NODE, so one sitting next to the v-if/v-else
   pair above turns this single-root component into a FRAGMENT. That empties
   `wrapper.classes()` in tests and — the part that actually matters — silently drops
   attribute fallthrough onto the root. Keep the template's two roots adjacent.

   The glyph is NOT sized to make this box itself, and cannot be:
   Icon's root element is the <svg>, so a width on it scales the artwork edge to
   edge instead of centring it — and `display: grid` on an <svg> stops it rendering
   at all, which is exactly the empty square this first shipped as.

   Without the box the glyph WAS the box, and a music note is far smaller than the
   cover it stands in for: a list mixing tagged and untagged files got rows of two
   heights, and a flex row laying out cover-then-text (the play queue, the player
   bar) started its text in a different place per row.

   `xlarge` is `display: contents` rather than a square, so at hero size the wrapper
   leaves layout entirely and the arrangement is exactly what it was: that size has
   no width of its own — it fills its container — and a box here would be the second
   declaration of the one square the note above warns about.

   `flex: 0 0 auto` so the box holds its size in a flex row rather than being
   squeezed by a long title beside it. */
.cover-image__box {
    display: grid;
    place-items: center;

    box-sizing: border-box;
    flex: 0 0 auto;

    &--tiny {
        width: map.get(s.$c-cover-image, "tiny");
        height: map.get(s.$c-cover-image, "tiny");
    }

    &--xsmall {
        width: map.get(s.$c-cover-image, "xsmall");
        height: map.get(s.$c-cover-image, "xsmall");
    }

    &--small {
        width: map.get(s.$c-cover-image, "small");
        height: map.get(s.$c-cover-image, "small");
    }

    &--large {
        width: map.get(s.$c-cover-image, "large");
        height: map.get(s.$c-cover-image, "large");
    }

    &--xlarge {
        display: contents;
    }
}
</style>
