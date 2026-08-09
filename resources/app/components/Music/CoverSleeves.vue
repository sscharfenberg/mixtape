<script setup lang="ts">
/******************************************************************************
 * CoverSleeves
 * A fanned stack of up to three album sleeves — the decorative "here is a pile of
 * records" flourish. An artist card on a genre page leads with one (GenreArtists); a
 * playlist's hero carries one in place of the artwork it has none of.
 *
 * Extracted from GenreArtists on 2026-08-09, when the second caller arrived. Nothing about
 * the effect changed — the tokens moved from `*.$c-genre-artists` to `*.$c-cover-sleeves`
 * intact — but the RULE below is now stated once instead of being a page's private detail.
 *
 * THE FAN DEGRADES HONESTLY, which is the load-bearing decision rather than a fallback. On
 * a genre page half of all artists have exactly one album and only a third have three or
 * more, so the three-cover fan is the MINORITY case and the one-cover stack is the common
 * one. Padding a stack out to three by repeating a sleeve, or by filling with placeholders,
 * would make the most frequent card on the page look like a rendering fault. So: three or
 * more covers fan three, two fan two, one sits straight, and nothing at all gets a single
 * placeholder.
 *
 * IT NEVER PICKS OR RE-ORDERS. The covers arrive already chosen and already shuffled by the
 * server — at random, per request, so the fan differs on every visit by design — and this
 * renders exactly what it was handed, in the order it was handed them. Shuffling here as
 * well would apply the randomness twice, and the middle sleeve would stop being the middle
 * one that was sent.
 *
 * TWO SCALES, `card` and `hero`, and the split was resisted for a while: the first version
 * took no size at all, on the grounds that the fan is the same object wherever it appears
 * and a prop would mean keeping an offset and a box per step. What changed that is that one
 * number genuinely cannot serve both — at the card's 96px the hero reads as an afterthought
 * in a wide panel, and at the hero's 144px a card's fan is wider than the grid column
 * holding it. So the triple is per scale (see s.$c-cover-sleeves for why it IS a triple),
 * `card` is the default, and the genre page passes nothing.
 *
 * The two scales also draw their artwork differently, which is the one wrinkle worth
 * knowing. `card` hands CoverImage its `large` step, whose 96px is intrinsic. `hero` hands
 * it `xlarge`, which has no width of its own and fills whatever it is given — so at that
 * scale the SLEEVE declares the square and the image fills it. That is deliberate rather
 * than a workaround: `xlarge` also brings the heavier `featured` frame and rounding, which
 * is exactly right at 144px and is what GenreArtists rejected at 96px ("a picture frame
 * around the art rather than the edge of a record"). The sleeve's own corner follows, or it
 * would clip a different curve than the artwork inside it.
 *
 * ALWAYS DECORATIVE, hence `aria-hidden` on the root and `decorative` on every sleeve. In
 * both callers the fan sits directly beside the name of the thing it illustrates, and
 * naming each sleeve would have a screen reader read three album titles before reaching the
 * artist or the playlist. A caller that needed the artwork announced would not want a fan.
 *
 * Rotation is a STATIC transform, not a transition, so it needs no reduced-motion guard.
 *****************************************************************************/
import { computed } from "vue";
import type { CoverSize } from "Components/Music/CoverImage/CoverImage.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";

/** Where a sleeve sits in the stack — the modifier that places (and tips) it. */
export type SleevePosition = "left" | "right" | "middle" | "single";

/**
 * How big the stack is drawn. `card` is a thumbnail illustrating a name in a grid;
 * `hero` stands in for the artwork a detail page's subject does not have.
 */
export type SleeveScale = "card" | "hero";

/** One drawn sleeve: the artwork to put in it, or null for the placeholder. */
export type Sleeve = {
    src: string | null;
    position: SleevePosition;
};

const props = withDefaults(
    defineProps<{
        /**
         * Up to three cover URLs, already picked and already shuffled by the server. Empty is
         * a normal state — nothing on file carries artwork — and renders one placeholder.
         */
        covers: string[];
        /**
         * What the stack illustrates (an artist, a playlist). Passed to CoverImage, which
         * needs it for the placeholder's box; never read out, since the fan is decorative.
         */
        title: string;
        /** How big to draw it. `card` by default, which is what a grid of them wants. */
        scale?: SleeveScale;
    }>(),
    { scale: "card" }
);

/**
 * Which CoverImage step each scale asks for — see the banner for why they differ.
 *
 * `xlarge` at hero scale has no width of its own, so the SLEEVE declares the square there
 * and the image fills it; `large` at card scale is intrinsically 96px and sizes itself.
 */
const coverSize = computed<CoverSize>(() => (props.scale === "hero" ? "xlarge" : "large"));

/**
 * The sleeves to draw, in DOM order, each with the class that places it.
 *
 * Built as an explicit list rather than left to `v-for` over `covers` with index maths in
 * the template, because the middle sleeve must come LAST in the DOM: the three overlap, and
 * the one on top is the one painted last. Doing it with z-index instead would work until
 * the fan sits inside a stacking context of its own — a tab panel, a hero — which is
 * exactly the sort of thing that breaks silently much later.
 */
const sleeves = computed<Sleeve[]>(() => {
    const covers = props.covers.slice(0, 3);

    if (covers.length === 0) return [{ src: null, position: "single" }];
    if (covers.length === 1) return [{ src: covers[0], position: "single" }];
    if (covers.length === 2) {
        return [
            { src: covers[0], position: "left" },
            { src: covers[1], position: "right" }
        ];
    }

    return [
        { src: covers[0], position: "left" },
        { src: covers[2], position: "right" },
        { src: covers[1], position: "middle" }
    ];
});
</script>

<template>
    <span :class="['cover-sleeves', `cover-sleeves--${scale}`]" aria-hidden="true">
        <span
            v-for="(sleeve, index) in sleeves"
            :key="index"
            :class="['cover-sleeves__sleeve', `cover-sleeves__sleeve--${sleeve.position}`]"
        >
            <!-- `large` at card scale, `xlarge` at hero scale — see the banner and
                 `coverSize` for why the two differ rather than one step being scaled. -->
            <cover-image :src="sleeve.src" :title="title" :size="coverSize" decorative />
        </span>
    </span>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The scale's triple, published as custom properties so the sleeve rules below are written
   ONCE and each variant only restates the three numbers that differ. The alternative —
   duplicating the whole sleeve block per scale — is four more places for the transform maths
   and the shadow to drift apart. */
@mixin scale($name) {
    $triple: map.get(s.$c-cover-sleeves, $name);

    --cover-sleeve-size: #{map.get($triple, "size")};
    --cover-sleeve-offset: #{map.get($triple, "offset")};
    --cover-sleeve-radius: #{map.get($triple, "radius")};

    /* `extent`, not `size`, for the height — and `box` already carries the same allowance
       across. A sleeve tipped by 9° is 14.4% taller and wider than its own side, so a box
       measured from the untipped square leaves the outer two hanging over whatever contains
       it: invisible inside a genre card's padding, and plainly wrong in a hero, where the box
       hugs its content and the overhang crossed the panel's inset. The token derives both
       from the angle so they cannot drift from it. */
    width: map.get($triple, "box");
    height: map.get($triple, "extent");
}

/* The fan's box. `position: relative` so the sleeves can stack on top of one another, and
   BOTH DIMENSIONS ARE DECLARED because they are absolutely positioned and contribute nothing
   to layout on their own — without a size here the box is 0×0, every card holding one
   collapses to its text, and the sleeves paint outside whatever contains them.

   The width is the reserved box rather than `100%`, and that distinction cost a layout bug:
   `width: 100%` needs a parent with a definite width, and it silently resolves to ZERO inside
   a shrink-to-fit one — which is exactly what a HeroSection's `unframedCover` slot is. The fan
   then had no box at all and its sleeves hung out over the panel's corner. `max-width: 100%`
   keeps the other direction covered: in a grid card narrower than the stack the box shrinks,
   and the sleeves simply overlap further. */
.cover-sleeves {
    display: flex;
    position: relative;
    align-items: center;
    justify-content: center;

    max-width: 100%;

    &--card {
        @include scale("card");
    }

    &--hero {
        @include scale("hero");
    }
}

/* ONE SLEEVE, sized from the scale's custom properties rather than by its content.
   Declaring the square here is what lets the hero scale work at all: it renders CoverImage's
   `xlarge`, which has no width of its own and fills its container — and whose PLACEHOLDER is
   `display: contents`, so without a box here a hero fan with no artwork would collapse to
   three bare glyphs. At card scale the numbers simply agree with the 96px `large` draws
   itself, so nothing is fighting.

   `place-items: center` centres that placeholder glyph, which is far smaller than the sleeve
   it stands in for. `aspect-ratio` rather than a height, so the square follows the one width. */
.cover-sleeves__sleeve {
    display: grid;
    position: absolute;
    place-items: center;

    overflow: hidden;

    width: var(--cover-sleeve-size);
    aspect-ratio: 1;

    border-radius: var(--cover-sleeve-radius);

    /* Each sleeve carries the shadow that lifts it off the one beneath; the fan is built
       from overlap, so this is what keeps the stack legible as separate records rather than
       one flat shape. CoverImage draws the hairline edge itself. */
    box-shadow: 0 2px 6px -1px map.get(c.$c-cover-sleeves, "shadow");

    line-height: 0;

    /* Deliberately OUTSIDE any reduced-motion guard: these are static transforms, not
       motion. The rule covers transitions and running animations; a sleeve that is simply
       drawn at an angle animates nothing. */
    &--left {
        transform: translateX(calc(-1 * var(--cover-sleeve-offset)))
            rotate(calc(-1 * #{map.get(s.$c-cover-sleeves, "angle")}));
    }

    &--right {
        transform: translateX(var(--cover-sleeve-offset)) rotate(map.get(s.$c-cover-sleeves, "angle"));
    }

    /* Straight on, and painted last — it is the one on top. */
    &--middle,
    &--single {
        transform: none;
    }
}
</style>
