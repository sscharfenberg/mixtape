<template>
    <div class="modal-dialog__footer"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.modal-dialog__footer {
    display: flex;
    align-items: center;

    // A lone action hugs the trailing edge; a pair (or more) spreads to both
    // edges. Consumers just drop their buttons in — no alignment classes needed.
    justify-content: flex-end;

    padding: map.get(s.$c-modal, "padding");
    border-top: map.get(s.$c-modal, "border") solid map.get(c.$c-modal, "footer-border");
    gap: 2ch;

    background-color: map.get(c.$c-modal, "footer-background");
    color: map.get(c.$c-modal, "footer-surface");
    border-end-end-radius: calc(#{map.get(s.$c-modal, "radius")} - #{map.get(s.$c-modal, "border")});
    border-end-start-radius: calc(#{map.get(s.$c-modal, "radius")} - #{map.get(s.$c-modal, "border")});

    &:has(> :nth-child(2)) {
        justify-content: space-between;
    }
}
</style>
