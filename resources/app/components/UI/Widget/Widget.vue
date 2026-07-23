<script setup lang="ts">
/******************************************************************************
 * Widget
 * A content card assembled from the WidgetTitle / WidgetBody / WidgetFooter
 * parts, used to lay out the browse pages. Slots: #title (optional header
 * strip), default (the body), #footer (optional). While `loading` is true a
 * WidgetLoader overlay covers the whole card. Drop several inside a WidgetGroup
 * for the responsive grid.
 *****************************************************************************/
import WidgetBody from "./WidgetBody.vue";
import WidgetFooter from "./WidgetFooter.vue";
import WidgetLoader from "./WidgetLoader.vue";
import WidgetTitle from "./WidgetTitle.vue";

withDefaults(
    defineProps<{
        /** Show the loading overlay (a centered spinner) over the whole card. */
        loading?: boolean;
    }>(),
    {
        loading: false
    }
);
</script>

<template>
    <div class="widget">
        <widget-title v-if="$slots.title"><slot name="title" /></widget-title>
        <widget-body><slot /></widget-body>
        <widget-footer v-if="$slots.footer"><slot name="footer" /></widget-footer>
        <widget-loader v-if="loading" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.widget {
    // A subgrid card: it occupies the group's title / body / footer row bands
    // (grid-row: span 3) and subgrids into them (grid-template-rows: subgrid), so
    // those bands share a height across a row and every footer lines up. row-gap 0
    // keeps the sections flush; only the group gap spaces cards apart. Solid
    // surface — the browse pages sit on a solid background, so frosted glass would
    // blur nothing here.
    display: grid;
    position: relative; // positioning context for the WidgetLoader overlay
    grid-template-rows: subgrid;
    grid-row: span 3;
    isolation: isolate; // contain the loader overlay's z-index to this card

    overflow: hidden; // clip the title strip to the card's rounded corners
    border: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "border");
    row-gap: 0;

    background-color: map.get(c.$c-widget, "background");
    color: map.get(c.$c-widget, "surface");
    border-radius: map.get(s.$c-widget, "radius");
}
</style>
