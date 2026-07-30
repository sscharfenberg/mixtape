<script setup lang="ts">
/******************************************************************************
 * Card
 * A titled panel for a detail page's content: an optional heading, then whatever
 * the caller slots in. Deliberately generic — a song's stored facts sit in these
 * today (via Facts), and the album / artist / genre pages will put their own
 * content in the same panel, which is the point: one surface, so a detail page
 * reads the same wherever you arrive.
 *
 * NOT a Widget. A Widget is the browse pages' card and ships a loader overlay, a
 * refresh footer and a skeleton state that static detail content never uses. This
 * keeps the Widget's *surface* (fill, border, radius, and the same ~300px minimum
 * width, via tokens that mirror its picks) and none of its chrome: the title is bare
 * type in the app's h2 ink and headline family, because a page of facts should be
 * quiet.
 *
 * Drop several inside a CardGroup for the wrapping row; `wide` gives one a row to
 * itself. Sizing lives here rather than in the group because a card is what knows
 * how wide it wants to be — see CardGroup for how the two halves meet.
 *****************************************************************************/
withDefaults(
    defineProps<{
        /**
         * Heading text, already translated. Rendered as an <h2>, which assumes the host
         * page's own title is its h1 — true of every detail page here. Omit for an
         * untitled panel.
         */
        title?: string;
        /**
         * Take a whole row rather than sharing one, for content that needs the width (a
         * file path). Opt-in, because a mostly-empty card stretched across the page reads
         * worse than a narrow one.
         */
        wide?: boolean;
    }>(),
    {
        title: undefined,
        wide: false
    }
);
</script>

<template>
    <div class="card" :class="{ 'card--wide': wide }">
        <h2 v-if="title" class="card__title">{{ title }}</h2>
        <div class="card__body"><slot /></div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* A column: title, then body. Equal `flex-basis` across cards is what makes those
   sharing a line equal in width (CardGroup hands the leftover space back with
   `flex-grow`), and the group's default `align-items: stretch` makes them equal in
   height.

   Solid surface, no frosted glass: a detail page sits on a solid background, so there
   would be nothing behind the panel to blur. */
.card {
    display: flex;
    flex-direction: column;

    flex: 1 1 map.get(s.$c-card, "basis");

    border: map.get(s.$c-card, "border") solid map.get(c.$c-card, "border");

    background-color: map.get(c.$c-card, "background");
    color: map.get(c.$c-card, "surface");
    border-radius: map.get(s.$c-card, "radius");
}

/* A basis of the whole line, so the card takes a row to itself at every width — no
   breakpoint needed, and (unlike a grid column span) nothing left empty beside it. */
.card--wide {
    flex-basis: 100%;
}

/* Bare type on the panel: no filled band, no rule under it. The padding omits its
   bottom side on purpose — the body brings its own top padding, and doubling the two
   would open a gap wider than the panel's own inset. `margin: 0` because this is an
   <h2> and the spacing here is padding, not UA margins. */
.card__title {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-card, "padding") map.get(s.$c-card, "padding") 0;
    margin: 0;
    gap: 0.5ch;

    color: map.get(c.$c-card, "title");

    font-family: map.get(t.$c-card, "title");
    font-size: map.get(s.$c-card, "title-font-size");

    /* Explicit, because an <h2> is bold by UA default and this is neither that nor
       <Headline>'s own weight: at 200 the headline family is airy enough to carry a
       banner-sized heading, but a 1.3rem card title set that light reads as a caption
       rather than as the thing it names. */
    font-weight: 600;
}

/* `flex: 1` so the body fills whatever height the card was stretched to, and `grid`
   so the slotted content fills the body in turn — a block child would sit at its own
   height and leave the panel's bottom empty, and a grid item stretches by default
   without this component having to reach into the slot with `:deep`. */
.card__body {
    display: grid;

    flex: 1;

    padding: map.get(s.$c-card, "padding");
}
</style>
