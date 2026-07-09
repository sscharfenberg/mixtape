<script setup lang="ts">
/******************************************************************************
 * LoadingSpinner
 * A small CSS spinner (ported from cantrip.me). The visual styles live in
 * resources/app/styles/components/progress/_loading-spinner.scss; this
 * component only wires the per-instance size into the `--pixel-size` custom
 * property. Pass the `colored` class to tint it with the pending state.
 *****************************************************************************/
const props = withDefaults(defineProps<{ size?: number }>(), { size: 4 });
const pixelSize = `${props.size * 12}px`;
</script>

<template>
    <span class="loading-spinner"></span>
</template>

<style scoped lang="scss">
.loading-spinner {
    --pixel-size: v-bind(pixelSize);
}
</style>
