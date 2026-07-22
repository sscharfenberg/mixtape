<template>
    <div class="widget__footer"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.widget__footer {
    display: flex;
    align-items: center;

    // A lone action hugs the trailing edge; a pair (or more) spreads to both
    // edges — consumers just drop controls in, no alignment class (mirrors ModalFooter).
    justify-content: flex-end;

    padding: map.get(s.$c-widget, "padding");
    border-top: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "footer-border");
    gap: 1ch;

    color: map.get(c.$c-widget, "footer-surface");

    &:has(> :nth-child(2)) {
        justify-content: space-between;
    }
}
</style>
