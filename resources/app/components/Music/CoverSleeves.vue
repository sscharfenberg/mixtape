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
 * ONE SIZE, deliberately: a sleeve is CoverImage's `large` step and the fan's offset, tip
 * and reserved box are tuned to it. A `size` prop would need its own offset and box per
 * step, which is three more tokens to keep in step for a flourish that is the same object
 * wherever it appears.
 *
 * ALWAYS DECORATIVE, hence `aria-hidden` on the root and `decorative` on every sleeve. In
 * both callers the fan sits directly beside the name of the thing it illustrates, and
 * naming each sleeve would have a screen reader read three album titles before reaching the
 * artist or the playlist. A caller that needed the artwork announced would not want a fan.
 *
 * Rotation is a STATIC transform, not a transition, so it needs no reduced-motion guard.
 *****************************************************************************/
import { computed } from "vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";

/** Where a sleeve sits in the stack — the modifier that places (and tips) it. */
export type SleevePosition = "left" | "right" | "middle" | "single";

/** One drawn sleeve: the artwork to put in it, or null for the placeholder. */
export type Sleeve = {
    src: string | null;
    position: SleevePosition;
};

const props = defineProps<{
    /**
     * Up to three cover URLs, already picked and already shuffled by the server. Empty is a
     * normal state — nothing on file carries artwork — and renders one placeholder.
     */
    covers: string[];
    /**
     * What the stack illustrates (an artist, a playlist). Passed to CoverImage, which needs
     * it for the placeholder's box; never read out, since the fan is decorative.
     */
    title: string;
}>();

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
    <span class="cover-sleeves" aria-hidden="true">
        <span
            v-for="(sleeve, index) in sleeves"
            :key="index"
            :class="['cover-sleeves__sleeve', `cover-sleeves__sleeve--${sleeve.position}`]"
        >
            <!-- `large` is the 96px step, which is exactly what a sleeve is. NOT `xlarge`:
                 that one fills its container but carries the HERO frame with it — the thick
                 `featured` border and rounding meant for a 240px sleeve — which at this size
                 reads as a picture frame around the art rather than as the edge of a record. -->
            <cover-image :src="sleeve.src" :title="title" size="large" decorative />
        </span>
    </span>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The fan's box. `position: relative` so the sleeves can stack on top of one another, and a
   FIXED height because they are absolutely positioned and would otherwise contribute
   nothing to the layout — every card holding one would collapse to its text.

   `width: 100%` up to the reserved box, so the fan centres itself in whatever it is placed
   in: a card column that is narrower than the stack, or a hero's cover square that is wider
   than it. */
.cover-sleeves {
    display: flex;
    position: relative;
    align-items: center;
    justify-content: center;

    width: 100%;
    max-width: map.get(s.$c-cover-sleeves, "box");
    height: map.get(s.$c-cover-sleeves, "size");
}

/* Sized by its content — the CoverImage inside is already exactly one sleeve wide — so
   there is no second copy of that measurement here to drift from it. `line-height: 0`
   because the image is inline content and would otherwise sit on a text baseline, leaving a
   sliver of box beneath it for the shadow to trace. */
.cover-sleeves__sleeve {
    position: absolute;

    overflow: hidden;

    border-radius: map.get(s.$c-cover-sleeves, "radius");

    /* Each sleeve carries the shadow that lifts it off the one beneath; the fan is built
       from overlap, so this is what keeps the stack legible as separate records rather than
       one flat shape. CoverImage draws the hairline edge itself. */
    box-shadow: 0 2px 6px -1px map.get(c.$c-cover-sleeves, "shadow");

    line-height: 0;

    /* Deliberately OUTSIDE any reduced-motion guard: these are static transforms, not
       motion. The rule covers transitions and running animations; a sleeve that is simply
       drawn at an angle animates nothing. */
    &--left {
        transform: translateX(calc(-1 * #{map.get(s.$c-cover-sleeves, "offset")}))
            rotate(calc(-1 * #{map.get(s.$c-cover-sleeves, "angle")}));
    }

    &--right {
        transform: translateX(map.get(s.$c-cover-sleeves, "offset")) rotate(map.get(s.$c-cover-sleeves, "angle"));
    }

    /* Straight on, and painted last — it is the one on top. */
    &--middle,
    &--single {
        transform: none;
    }
}
</style>
