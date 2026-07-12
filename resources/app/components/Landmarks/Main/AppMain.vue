<script setup lang="ts"></script>
<template>
    <main class="frosted-glass"><slot /></main>
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
    margin: 2lh auto;
}
</style>
