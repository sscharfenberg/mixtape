<script setup lang="ts">
/******************************************************************************
 * ModalBody
 * The scrollable content region of a Modal (default slot). When the modal has
 * no footer it rounds off its own bottom edge (see `hasFooter`).
 *****************************************************************************/
withDefaults(
    defineProps<{
        /** Whether the parent Modal renders a footer; when false, ModalBody rounds its own bottom corners. */
        hasFooter?: boolean;
    }>(),
    {
        hasFooter: false
    }
);
</script>

<template>
    <div id="modal-body" :class="['modal-dialog__body', { 'modal-dialog__body--no-footer': !hasFooter }]"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

.modal-dialog__body {
    overflow-y: auto;

    // `min-height: 0` lets this flex item shrink below its intrinsic content
    // size so the parent's `max-height` cap wins and the scrollbar lives here.
    min-height: 0;
    flex: 1 1 auto;

    padding: map.get(s.$c-modal, "padding");

    &--no-footer {
        margin-bottom: calc(#{map.get(s.$c-modal, "radius")} - #{map.get(s.$c-modal, "border")});
    }
}
</style>
