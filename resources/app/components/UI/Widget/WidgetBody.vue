<template>
    <div class="widget__body"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.widget__body {
    // Sits in the Widget's shared "body" subgrid row and stretches to fill it
    // (grid items stretch by default), so the footer band lines up across cards.
    padding: map.get(s.$c-widget, "padding");
}
</style>
