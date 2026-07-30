<script setup lang="ts">
/******************************************************************************
 * CardGroup
 * The wrapping row a set of Cards sits in. Layout only — no markup of its own
 * beyond the container — so the surface stays entirely Card's business.
 *
 * A flex row rather than an auto-fit grid, and the reason is Card's `wide`. An
 * auto-fit grid collapses tracks nothing is placed in, which is what keeps three
 * cards filling a four-track row — but a card spanning `1 / -1` occupies every
 * track, so none collapse and the row ends in dead space the width of a card. Grid
 * cannot express "span the tracks that are actually used"; a fixed `span 2` is a
 * magic number that overflows by inventing implicit columns wherever fewer tracks
 * exist. Flex has no tracks to leave empty: how many cards fit a line is decided by
 * their shared `flex-basis`, and `flex-grow` hands the leftover back to whichever
 * cards are on that line, so three cards on a line are three equal cards filling it.
 *****************************************************************************/
</script>

<template>
    <div class="card-group"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.card-group {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-card, "gap");
}
</style>
