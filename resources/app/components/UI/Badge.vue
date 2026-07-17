<script setup lang="ts">
/******************************************************************************
 * Badge
 * A small status pill (ported from cantrip.me). Used by the dashboard's
 * two-factor section to show enabled (success) / disabled (warning) state, but
 * generic: pick a state variant via `type`. Colour comes from c.$c-badge (the
 * shared $state palette), size from s.$c-badge.
 *****************************************************************************/
withDefaults(
    defineProps<{
        type?: "success" | "warning" | "error" | "caution";
    }>(),
    {
        type: "caution"
    }
);
</script>

<template>
    <span class="badge" :class="type"><slot /></span>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

@layer components {
    .badge {
        display: inline-flex;
        align-items: center;

        padding: map.get(s.$c-badge, "padding");
        border: map.get(s.$c-badge, "border") solid transparent;
        gap: 0.5ch;

        backdrop-filter: blur(12px);
        border-radius: map.get(s.$c-badge, "radius");

        font-weight: 700;

        &.success {
            background-color: map.get(c.$c-badge, "success", "background");
            color: map.get(c.$c-badge, "success", "surface");
            border-color: map.get(c.$c-badge, "success", "border");
        }

        &.warning {
            background-color: map.get(c.$c-badge, "warning", "background");
            color: map.get(c.$c-badge, "warning", "surface");
            border-color: map.get(c.$c-badge, "warning", "border");
        }

        &.error {
            background-color: map.get(c.$c-badge, "error", "background");
            color: map.get(c.$c-badge, "error", "surface");
            border-color: map.get(c.$c-badge, "error", "border");
        }

        &.caution {
            background-color: map.get(c.$c-badge, "caution", "background");
            color: map.get(c.$c-badge, "caution", "surface");
            border-color: map.get(c.$c-badge, "caution", "border");
        }
    }
}
</style>
