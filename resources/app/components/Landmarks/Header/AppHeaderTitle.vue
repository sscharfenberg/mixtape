<script setup lang="ts">
/******************************************************************************
 * AppHeaderTitle
 * The wordmark <h1> in the header (an Inertia <Link> home). Name comes from
 * VITE_APP_NAME (mirrors the backend APP_NAME — see below).
 *
 * THE SYNTHWAVE CHROME LIVES HERE NOW (2026-08-10), and this component is the
 * only thing in the app wearing it. It was a global `.text-chrome` class, shared
 * with a detail page's hero title and a playlist entry's; both of those took an
 * outlined treatment of their own that day, which left a shared class with one
 * consumer — so it came home, tokens and all (c.$c-title / s.$c-title).
 *
 * ONE SPAN, WHERE THERE WERE TWO STACKED COPIES of the app name. The effect needs
 * a gradient clipped to the glyphs AND a glow, and `text-shadow` paints above an
 * element's own background — so on a single element it washed over the gradient
 * it was meant to be lighting. This component's answer was two layers, one
 * carrying the shadow and one absolutely positioned over it carrying the clip,
 * which needs the name in the markup twice. `filter: drop-shadow()` filters the
 * element as already rendered, so the glow follows the glyph shapes and sits
 * BEHIND them with the gradient intact — the trick the shared class was written
 * with, brought back to the component that inspired it.
 *
 * THE CHROME ONLY BEGINS AT `landscape`, and below that the name is a flat tint.
 * Two reasons, both learned the hard way: the ramp splits at its own midline, and
 * a 1.2rem lockup is not tall enough for that split to read as anything but
 * noise; and the clip plus the stroke that carry it chew up type that small — a
 * stroke is drawn centred on the glyph outline, so it eats into letters whose
 * fill is already coming from a clipped background, and the edges break up.
 * "Choppy" was the owner's word for it.
 *
 * THE WHOLE MACHINERY THEREFORE SITS INSIDE THE MEDIA QUERY rather than being
 * undone by it. That is not tidiness: `-webkit-text-stroke-color` defaults to
 * `currentColor`, so a stroke WIDTH leaking below the breakpoint would quietly
 * embolden the flat-tint text — in dark mode only, that being where the theme
 * override sets one. Which is why the override is nested in there too.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;
</script>

<template>
    <h1>
        <Link href="/" prefetch>
            <span>{{ appName }}</span>
        </Link>
    </h1>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

// semantic heading only — generates no box (`display: contents`) so the <a>
// stays the header flex item and the visual is unchanged; `font: inherit`
// stops the UA h1's bold/2em from inheriting through to the title text.
h1 {
    display: contents;

    font: inherit;
}

a {
    position: relative;

    margin: 0;
    transform: skew(-15deg);

    text-decoration: none;
    letter-spacing: 0.03em;

    @include m.mqset(
        "font-size",
        #{map.get(s.$c-title, "font-size", "base")},
        #{map.get(s.$c-title, "font-size", "portrait")},
        #{map.get(s.$c-title, "font-size", "landscape")},
        #{map.get(s.$c-title, "font-size", "desktop")}
    );

    &::after {
        position: absolute;
        top: -0.1em;
        right: 0.05em;

        width: 0.4em;
        height: 0.4em;

        --twinkle: #{map.get(c.$c-title, "twinkle")};

        background:
            radial-gradient(
                var(--twinkle) 3%,
                color-mix(in srgb, var(--twinkle) 30%, transparent) 15%,
                color-mix(in srgb, var(--twinkle) 5%, transparent) 60%,
                transparent 80%
            ),
            radial-gradient(color-mix(in srgb, var(--twinkle) 20%, transparent) 50%, transparent 60%) 50% 50% / 5% 100%,
            radial-gradient(color-mix(in srgb, var(--twinkle) 20%, transparent) 50%, transparent 60%) 50% 50% / 70% 5%;
        background-repeat: no-repeat;

        content: "";
    }

    /* THE LETTERING. A BLOCK, because the ramp is tiled at one line box and positioned against
       this element's own box, and an inline box fragmented across lines gives it no reliable
       one to sit in. `overflow-wrap` is deliberately absent, unlike in the shared class this
       came from: that had to survive a caller's 40-character German compound, where this holds
       one configured app name. */
    span {
        $line-height: map.get(s.$c-title, "line-height");

        display: block;

        color: map.get(c.$c-title, "flat");

        line-height: $line-height;

        @include m.mq("landscape") {
            $rim: 0 0 map.get(s.$c-title, "rim") map.get(c.$c-title, "contour");

            background-image: map.get(c.$c-title, "gradient");

            /* ONE ramp PER LINE. A background paints over the element's whole box, so left to
               itself the ramp would stretch across every line at once: the first line in the
               dark-blue 25% region, the last in the pink 75% one, and the white specular line
               that should cross the letters landing in the gap between two of them. Sized to
               exactly one line box and tiled down instead, every line gets the full run.

               The height is `$line-height * 1em`, not `1lh`: `em` resolves against this
               element's font-size and `line-height` above is the same unitless number against
               the same font-size, so the tile matches the line box at every rung of the ladder
               with no dependency on `lh` support. */
            background-repeat: repeat-y;
            background-clip: text;
            background-size: 100% ($line-height * 1em);

            /* The chain's ORDER is the legibility. Each filter takes the previous result, so
               the near-black rim has to come first to hug the letters — put it after the neon
               and it rings the glow instead of the glyphs. It runs TWICE, because one pass at
               that radius cannot hold an edge against the bloom further out; the pair stands in
               for a second `-webkit-text-stroke`, an element having exactly one. */
            filter: drop-shadow($rim) drop-shadow($rim)
                drop-shadow(0 0 map.get(s.$c-title, "glow") map.get(c.$c-title, "glow"))
                drop-shadow(0 0 map.get(s.$c-title, "bloom") map.get(c.$c-title, "bloom"));
            -webkit-text-stroke: map.get(s.$c-title, "stroke") map.get(c.$c-title, "stroke");
            -webkit-text-fill-color: transparent;

            // Dark mode keeps a finer stroke — see the width token for why — nested in here
            // with the stroke it belongs to, per the note in the banner.
            @include m.theme-dark("span") {
                -webkit-text-stroke-width: map.get(s.$c-title, "stroke-dark");
            }
        }
    }
}
</style>
