<template>
    <div class="widget-group"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

// A responsive card grid: as many equal columns as fit, each at least
// `group-min` wide but never wider than its row — `min(<group-min>, 100%)`
// keeps a lone card from overflowing a narrow viewport. `dense` backfills gaps
// so cards that span more than one track don't leave holes.

// Each Widget spans three implicit row tracks (title / body / footer) and
// subgrids into them, so those bands share a height across a row and every
// card's footer lines up. This assumes the widgets in a group share that
// structure — a card that omits a section just leaves its band empty.
.widget-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-widget, "group-min")}, 100%), 1fr));
    grid-auto-flow: dense;

    gap: map.get(s.$c-widget, "group-gap");
}
</style>
