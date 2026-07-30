<script setup lang="ts">
/******************************************************************************
 * HeroSection
 * The first row of a detail page: a square of art beside a title and one line of
 * metadata, in a panel framed by a slowly rotating gradient ring. Generic by
 * construction — a song fills it today, an album / artist / genre will fill it with
 * their own art and credits — so it takes everything through slots and knows nothing
 * about what it is describing.
 *
 * Three slots, all optional:
 *   #cover     the artwork. Pass an <img> and it is sized to fill the square; pass
 *              anything else (an <Icon>, say) and the square is drawn as a dashed
 *              neon placeholder around it instead — see the `:has(img)` note in the
 *              styles for how that switch works without a prop.
 *   #title     the heading element. Its UA type is flattened so the hero's own
 *              headline face and size win, which lets the caller choose the level
 *              (an <h1> on a page whose title lives here, and nothing else has to
 *              know).
 *   #metadata  the line under the title — artist · album · year for a song. Rendered
 *              in the muted tint, because the cover already supplies the colour.
 *
 * It is a panel of its own rather than a Card: same fill, but framed by the rotating
 * ring instead of a flat border, which is what makes the top of a detail page read as
 * its loudest element without a second card style.
 *****************************************************************************/
</script>

<template>
    <div class="hero-section">
        <div class="hero-section__cover"><slot name="cover" /></div>

        <div class="hero-section__meta">
            <div class="hero-section__title"><slot name="title" /></div>
            <p class="hero-section__metadata"><slot name="metadata" /></p>
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
@use "Abstracts/typography" as t;
@use "Abstracts/z-indexes" as z;

/* The border gradient's angle, as a REGISTERED custom property — the registration is
   what makes the rotation possible at all: an unregistered `--angle` is an untyped
   token to the animation engine, so the gradient would jump between keyframes instead
   of sweeping. Typed as `<angle>`, it interpolates. Same mechanism as
   `.glowing-border`'s `--glowing-deg` (styles/components/_glowing-border.scss), down to
   the motion guard below.

   `@property` is a global at-rule — Vue's scoped transform only rewrites selectors —
   so the name is component-prefixed to keep the app-wide registry collision-free. */
@property --hero-section-border-angle {
    syntax: "<angle>";
    inherits: false;
    initial-value: 135deg;
}

/* Exactly one turn, so the loop restarts on the frame it ended: any start angle works
   as long as the end is start + 360deg. */
@keyframes hero-section-border-rotate {
    to {
        --hero-section-border-angle: 495deg;
    }
}

/* Stacked on a phone (cover, then title — the reading order), side-by-side from
   `portrait` up, where a square cover plus a title line fit across without either being
   squeezed.

   The meta column is CENTRED against the cover rather than stretched to its height: it
   holds two short lines next to a ~240px square, and top-aligning them leaves a tall
   well of empty space under the text that reads as a missing element. Centred, the two
   halves of the row balance. */
.hero-section {
    display: grid;
    position: relative; // positioning context for the border ring below
    align-items: center;
    isolation: isolate; // keep the ring's rung contained to this panel

    padding: map.get(s.$c-hero-section, "padding");
    gap: map.get(s.$c-hero-section, "gap");

    background-color: map.get(c.$c-hero-section, "background");
    border-radius: map.get(s.$c-hero-section, "radius");

    @include m.mq("portrait") {
        grid-template-columns: auto 1fr;
    }

    /* The featured border, drawn as a gradient ring: fill the ::before with the hue ramp
       over the border-box, then mask the padding-box back out so only the ring survives —
       a plain `border` can only take one flat colour. Same technique as the shared
       `.frosted-glass`, but owned here: this frame is the hero's own decision, and it sits
       over the panel's opaque fill rather than glass.

       CONIC, not linear, and that is what makes the rotation even. A linear gradient's
       line is projected onto the box, so its length is |W·sin(a)| + |H·cos(a)| — on a
       panel this much wider than it is tall, that swings by a factor of five as the angle
       turns, and the bands visibly crawl near the horizontal and race near the vertical
       even though the angle itself changes at a constant rate. A conic gradient sweeps
       around a centre point, so equal angle equals equal sweep the whole way round.
       `.glowing-border` uses one for the same reason.

       The ramp's stops come from the token as a list; the first is repeated as the last so
       360deg meets 0deg on the same colour instead of a hard seam. `border-radius:
       inherit` makes the ring follow the panel's corners, and it paints on the "raised"
       rung so it reads unbroken even where the cover square meets it. */
    &::before {
        $ramp: map.get(c.$c-hero-section, "border-ramp");

        position: absolute;
        inset: 0;
        z-index: z.$c-hero-section;

        border: map.get(s.$c-hero-section, "border") solid transparent;

        background: conic-gradient(from var(--hero-section-border-angle), #{$ramp}, #{list.nth($ramp, 1)})
            border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";

        pointer-events: none;

        /* Ambient, continuous motion, so it is opt-in behind `no-preference` like every
           other animation here — with the preference set (or unknown) the ring just holds
           the 135deg the property was registered with. */
        @media (prefers-reduced-motion: no-preference) {
            animation: hero-section-border-rotate ti.$c-hero-section linear infinite;
        }
    }
}

/* The frame around the cover slot: a fixed square that grows a step at the wider
   breakpoints, capped at 100% so the stacked phone layout can't push it past the
   panel's padding. `place-items: center` is for whatever a caller slots in that ISN'T
   an image — an icon standing in for missing art. */
.hero-section__cover {
    display: grid;
    place-items: center;

    overflow: hidden; // clip a non-square scan to the frame's corners

    width: map.get(s.$c-hero-section, "cover", "base");
    max-width: 100%;
    aspect-ratio: 1;

    border-radius: map.get(s.$c-hero-section, "cover-radius");

    @include m.mq("landscape") {
        width: map.get(s.$c-hero-section, "cover", "landscape");
    }

    @include m.mq("desktop") {
        width: map.get(s.$c-hero-section, "cover", "desktop");
    }

    /* Art fills the frame; `object-fit: cover` keeps a non-square scan from distorting.
       `:slotted` because the <img> belongs to the caller's scope, not this one — it is the
       supported way for a component to size what it was handed. */
    > :slotted(img) {
        width: 100%;
        height: 100%;

        object-fit: cover;
    }

    /* No art on disk: the same square, drawn as a dashed neon outline around whatever the
       caller put there instead (a muted icon), so the hero keeps its shape and the gap
       reads as "nothing here" rather than as a failed image.

       Keyed off `:has(img)` rather than a prop, because the DOM already answers the
       question — the caller decides by what it slots in, and cannot get the two out of
       sync by passing an image and forgetting the flag. */
    &:not(:has(img)) {
        border: map.get(s.$c-hero-section, "cover-placeholder-border") dashed
            map.get(c.$c-hero-section, "cover-placeholder-border");

        background-color: map.get(c.$c-hero-section, "cover-placeholder-background");
        color: map.get(c.$c-hero-section, "cover-placeholder-icon");
    }
}

.hero-section__meta {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-hero-section, "meta-gap");
}

/* The page's heading, wherever the caller puts its <h1>. Bigger than body text and
   tight-leaded so a long title wraps into a block rather than a ladder;
   `overflow-wrap` because titles do contain single unbroken monsters (a URL, a
   40-character German compound).

   The slotted heading has its UA type and margin flattened so this type wins: the
   caller picks the ELEMENT (which level belongs in the document outline) and the hero
   decides how it looks. */
.hero-section__title {
    overflow-wrap: anywhere;

    font-family: map.get(t.$c-hero-section, "title");
    font-size: map.get(s.$c-hero-section, "title-font-size");
    line-height: 1.1;

    > :slotted(*) {
        margin: 0;

        font-size: inherit;
        line-height: inherit;
    }
}

.hero-section__metadata {
    margin: 0;

    color: map.get(c.$c-hero-section, "metadata");
}
</style>
