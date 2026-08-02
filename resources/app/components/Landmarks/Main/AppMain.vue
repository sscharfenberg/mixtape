<script setup lang="ts">
/******************************************************************************
 * AppMain
 * The <main> content landmark that wraps every page's default slot. The scoped
 * style pins it to an explicit z-index (see the detailed note there) so page
 * dropdowns never paint behind the footer.
 *****************************************************************************/
</script>
<template>
    <main><slot /></main>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

/**
 * z-index 1 is needed here because without it, select options wrap behind the footer:
 *
 *   - footer's backdrop-filter silently creates a stacking context with z-index: auto
 *   - z-index: auto and z-index: 0 are painted in the same step, ordered by DOM position —
 *     footer comes last, so it won
 *   - z-index: 1 on <main> moves it into the next paint step (positive z-index), which always
 *     renders after the auto step regardless of DOM order
 *   - The dropdown's z-index: 6 then resolves normally within <main>'s stacking context
 *
 * Worth knowing for the future: any CSS property that creates a stacking context without an
 * explicit numeric z-index (backdrop-filter, opacity, transform, filter, isolation: isolate) is
 * the silent culprit in most "my z-index isn't working" bugs.
 */
main {
    position: relative;
    z-index: z.$c-main;

    width: 100%;
    min-height: 20rem;

    // Room for the PlayerBar, which is FIXED to the bottom of the viewport and so
    // out of the flow — without this it sits on top of the last rows of a page.
    // The variable is published by PlayerBar (mirroring the header's
    // `--app-header-height`) and removed when it unmounts, so the fallback of 0 is
    // what applies whenever the ordinary footer is showing instead.
    padding-bottom: var(--app-player-height, 0);
    margin: 2lh auto;

    // Any DataTable on any page pins its sticky header just below the app header
    // (which publishes its live height as --app-header-height, like StickyNav),
    // instead of behind it. Consumed by .dt-head's `top`; set here once, app-wide.
    --datatable-sticky-offset: var(--app-header-height);
}
</style>
