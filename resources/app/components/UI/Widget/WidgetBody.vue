<template>
    <div class="widget__body"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.widget__body {
    // Grow so footers line up at the bottom when a grid row stretches cards to
    // equal height (Widget is a column flex container).
    flex: 1 1 auto;

    padding: map.get(s.$c-widget, "padding");
}
</style>
