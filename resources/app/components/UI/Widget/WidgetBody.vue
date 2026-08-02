<script setup lang="ts">
/******************************************************************************
 * WidgetBody
 * The Widget's body region (the slot content), sitting in the card's shared
 * "body" subgrid band and stretching to fill it. With `centered`, the content is
 * centred *vertically* within that band (it still stretches full-width) — for a
 * widget whose body is shorter than the row's tallest card, like the stats
 * widget. Toggled by the Widget's `centered` prop.
 *****************************************************************************/
defineProps<{
    /** Centre the content vertically within the body band (it stays full-width). */
    centered?: boolean;
}>();
</script>

<template>
    <div class="widget__body" :class="{ 'widget__body--centered': centered }"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.widget__body {
    // A GRID ITEM's `min-width` defaults to `auto`, which resolves to its min-content
    // size — so a body holding anything unbreakable (a long song title on one line, a
    // wide table) grows past the card instead of being contained by it. That failure is
    // doubly confusing because the card itself clips: the content is cut off at a width
    // nothing on screen explains, and any `text-overflow: ellipsis` inside never fires,
    // since the element was never actually overflowing its own box. `min-width: 0` lets the
    // body be as narrow as the card, which is what puts the overflow back where a child
    // can handle it.
    min-width: 0;

    // Sits in the Widget's shared "body" subgrid row and stretches to fill it
    // (grid items stretch by default), so the footer band lines up across cards.
    padding: map.get(s.$c-widget, "padding");

    // Optional: centre the content in the band. Flex-column with centred main
    // axis distributes the spare vertical space above/below; the default
    // `align-items: stretch` keeps the content full-width (so an auto-fit grid
    // inside still has a definite width to lay out against).
    &--centered {
        display: flex;
        justify-content: center;
        flex-direction: column;
    }
}
</style>
