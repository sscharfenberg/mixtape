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
.widget-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-widget, "group-min")}, 100%), 1fr));
    grid-auto-flow: dense;

    gap: map.get(s.$c-widget, "group-gap");
}
</style>
