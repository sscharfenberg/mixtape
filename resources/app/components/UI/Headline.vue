<script setup lang="ts">
/******************************************************************************
 * Headline
 * The shared section heading (ported from cantrip.me). Renders an <h2> / <h3>
 * / <h4> chosen by `size` (default 2), with the heading content in the default
 * slot and an optional right-aligned #right slot (e.g. a count or action). An
 * optional `anchorId` sets the element `id` so the heading can be a scroll /
 * link target. Set `glow` to dress the heading in the shared glowing gradient
 * border (styles/components/_glowing-border.scss) instead of the default
 * underline; `align` ("left" default / "right") picks which edge the tab hugs
 * via the base vs `--right` glowing-border variant. All styling comes from the
 * contextual tokens (c.$c-headline / s.$c-headline / t.$c-headline).
 *
 * THE DEFAULT SLOT IS WRAPPED IN ONE ELEMENT (`.headline__content`), which is
 * not tidiness — it is the fix for a long title dropping BELOW its own icon
 * (2026-08-11, found on a song whose name runs to four slash-separated clauses).
 * The heading is a WRAPPING flex row, and flex collects items into lines by
 * their max-content size: an unwrapped title is an anonymous flex item, so a
 * name wider than the row was pushed onto a line of its own before it ever got
 * the chance to shrink and wrap inside one. The wrapper takes `flex-basis: 0`,
 * so it always fits on the first line, and wraps its own text internally — the
 * icon stays beside it. The `#right` slot keeps the heading's own wrap, which
 * is what lets a badge drop below on a phone.
 *****************************************************************************/
withDefaults(
    defineProps<{
        /** Heading level to render: 2 → h2, 3 → h3, 4 → h4. */
        size?: 2 | 3 | 4;
        /** Optional element id, so the heading can be a scroll / anchor target. */
        anchorId?: string;
        /** Wrap the heading in the glowing gradient border. */
        glow?: boolean;
        /** Which edge the glowing border hugs: "left" (default) or "right". */
        align?: "left" | "right";
    }>(),
    {
        size: 2,
        glow: false,
        align: "left"
    }
);
</script>

<template>
    <h2
        v-if="size === 2"
        :id="anchorId"
        :class="{ 'glowing-border': glow, 'glowing-border--right': glow && align === 'right' }"
    >
        <span class="headline__content"><slot /></span>
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h2>
    <h3
        v-if="size === 3"
        :id="anchorId"
        :class="{ 'glowing-border': glow, 'glowing-border--right': glow && align === 'right' }"
    >
        <span class="headline__content"><slot /></span>
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h3>
    <h4
        v-if="size === 4"
        :id="anchorId"
        :class="{ 'glowing-border': glow, 'glowing-border--right': glow && align === 'right' }"
    >
        <span class="headline__content"><slot /></span>
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h4>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;
@use "Abstracts/colors" as c;

h2,
h3,
h4 {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    margin: 0 0 0.5lh;
    gap: 0.5ch;

    font-family: t.$c-headline;
    font-weight: 200;
}

/* The heading's own content — an icon and a title, or just a title — held as ONE flex item so
   a long title cannot be pushed onto a line of its own. See the component banner for the
   mechanism. What each declaration is actually doing:

   EXISTING AT ALL is the fix. The icon and the title are flex items of THIS box, which does
   not wrap, so the title shrinks and wraps its own text rather than being moved off the line.
   Unwrapped they were items of the heading, which wraps on purpose — and flex collects items
   into lines by their max-content size, so a title wider than the row went below the icon
   before it was ever given the chance to shrink. Verified by taking the wrapper back out and
   watching browse.spec's heading test fail.

   `flex: 1 1 0` — a flex-basis of ZERO, so this item's hypothetical main size is 0. That
   changes nothing when it is alone (the first item on a line is collected whether it fits or
   not) and everything when a `#right` badge is present: at `auto` the title's max-content
   would push that badge onto a second line at every width a long title happens to have.

   `min-width: 0` — a flex item's automatic minimum is its min-content size, so without this a
   single unbreakable word longer than the row would push the box wider than the heading. */
.headline__content {
    display: flex;
    align-items: center;

    min-width: 0;
    flex: 1 1 0;

    gap: 0.5ch;

    /* The icon keeps its declared square. It is a flex item now — it was one of the heading's
       own before, but never had to survive sharing a line with a title that wanted the whole
       row — and the default `flex-shrink: 1` would let a long title squash it. */
    > :slotted(.icon) {
        flex: none;
    }
}

// The underline is the default decoration; when `glow` dresses the heading as a
// glowing border, that global class owns all four borders, so skip the rule.
h2:not(.glowing-border) {
    border-bottom: map.get(s.$c-headline, "h2", "border") solid map.get(c.$c-headline, "h2", "border");
}

h3:not(.glowing-border) {
    border-bottom: map.get(s.$c-headline, "h3", "border") solid map.get(c.$c-headline, "h3", "border");
}

h4:not(.glowing-border) {
    border-bottom: map.get(s.$c-headline, "h4", "border") solid map.get(c.$c-headline, "h4", "border");
}

h2 {
    color: map.get(c.$c-headline, "h2", "surface");

    font-size: map.get(s.$c-headline, "h2", "font");
}

h3 {
    color: map.get(c.$c-headline, "h3", "surface");

    font-size: map.get(s.$c-headline, "h3", "font");
}

h4 {
    color: map.get(c.$c-headline, "h4", "surface");

    font-size: map.get(s.$c-headline, "h4", "font");
}

// Restore the auto start-margin the glowing-border--right modifier relies on
// (the grouped `margin` above resets it), while keeping the bottom rhythm.
// Scoped to the --right variant so a left-aligned heading (base .glowing-border
// only) keeps its start margin at 0 and hugs the left edge.
.glowing-border--right {
    margin-inline: auto 0;
}

.right {
    margin-left: auto;
}

.right :slotted(img) {
    vertical-align: -0.15em;
}
</style>
