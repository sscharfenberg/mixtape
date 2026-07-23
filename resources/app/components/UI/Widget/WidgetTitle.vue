<template>
    <div class="widget__title"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.widget__title {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-widget, "padding");

    // a bottom rule matching the card's own border (same width + colour)
    border-bottom: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "border");
    gap: 0.5ch;

    background: map.get(c.$c-widget, "title-background");
    color: map.get(c.$c-widget, "title-surface");

    font-weight: 600;
}
</style>
