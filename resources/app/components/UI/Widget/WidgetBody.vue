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
