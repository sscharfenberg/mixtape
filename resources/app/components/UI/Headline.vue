<script setup lang="ts">
/******************************************************************************
 * Headline
 * The shared section heading (ported from cantrip.me). Renders an <h2> / <h3>
 * / <h4> chosen by `size` (default 2), with the heading content in the default
 * slot and an optional right-aligned #right slot (e.g. a count or action). An
 * optional `anchorId` sets the element `id` so the heading can be a scroll /
 * link target. All styling comes from the contextual headline tokens
 * (c.$c-headline / s.$c-headline / t.$c-headline).
 *****************************************************************************/
withDefaults(
    defineProps<{
        /** Heading level to render: 2 → h2, 3 → h3, 4 → h4. */
        size?: 2 | 3 | 4;
        /** Optional element id, so the heading can be a scroll / anchor target. */
        anchorId?: string;
    }>(),
    {
        size: 2
    }
);
</script>

<template>
    <h2 v-if="size === 2" :id="anchorId">
        <slot />
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h2>
    <h3 v-if="size === 3" :id="anchorId">
        <slot />
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h3>
    <h4 v-if="size === 4" :id="anchorId">
        <slot />
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

h2 {
    border-bottom: map.get(s.$c-headline, "h2", "border") solid map.get(c.$c-headline, "h2", "border");

    color: map.get(c.$c-headline, "h2", "surface");

    font-size: map.get(s.$c-headline, "h2", "font");
}

h3 {
    border-bottom: map.get(s.$c-headline, "h3", "border") solid map.get(c.$c-headline, "h3", "border");

    color: map.get(c.$c-headline, "h3", "surface");

    font-size: map.get(s.$c-headline, "h3", "font");
}

h4 {
    border-bottom: map.get(s.$c-headline, "h4", "border") solid map.get(c.$c-headline, "h4", "border");

    color: map.get(c.$c-headline, "h4", "surface");

    font-size: map.get(s.$c-headline, "h4", "font");
}

.right {
    margin-left: auto;
}

.right :slotted(img) {
    vertical-align: -0.15em;
}
</style>
