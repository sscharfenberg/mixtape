<script setup lang="ts">
/******************************************************************************
 * FormLegend
 * Ported from cantrip.me's Form/FormLegend. A boxed list of contextual notes
 * shown above a form (e.g. "required fields" / "password strength" hints):
 * one <li> per slotted item, each with its own icon. An item is only rendered
 * when its slot actually has content, so callers can pass a fixed `items`
 * list and conditionally fill slots (e.g. a 2FA hint that only appears once
 * 2FA is required). An optional `modifier` class (e.g. "warning") recolours a
 * single item without affecting the others.
 *****************************************************************************/
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** One entry per legend item: which named slot fills it, its icon, and an optional state modifier class. */
    items: { slot: string; icon: string; modifier?: string }[];
}>();
</script>

<template>
    <ul class="form-legend">
        <template v-for="item in items" :key="item.slot">
            <li v-if="$slots[item.slot]" :class="item.modifier ? item.modifier : undefined">
                <icon :name="item.icon" />
                <span><slot :name="item.slot" /></span>
            </li>
        </template>
    </ul>
</template>

<style scoped lang="scss">
/**
 * Colours / sizes come from the contextual Abstracts tokens (c.$c-legend /
 * s.$c-legend). Wrapped in `@layer components` like FormInput / Checkbox.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

@layer components {
    .form-legend {
        display: flex;
        flex-direction: column;

        padding: 0;
        border: map.get(s.$c-legend, "border") solid map.get(c.$c-legend, "border");
        margin: 0;

        background-color: map.get(c.$c-legend, "background");
        color: map.get(c.$c-legend, "surface");

        list-style: none;
        border-radius: map.get(s.$c-legend, "radius");

        > li {
            display: flex;

            padding: map.get(s.$c-legend, "padding");
            gap: 1ch;

            &:first-child {
                padding-top: map.get(s.$c-legend, "padding-block-start");

                border-top-left-radius: calc(map.get(s.$c-legend, "radius") - map.get(s.$c-legend, "border"));
                border-top-right-radius: calc(map.get(s.$c-legend, "radius") - map.get(s.$c-legend, "border"));
            }

            &:last-child {
                padding-bottom: map.get(s.$c-legend, "padding-block-start");

                border-bottom-right-radius: calc(map.get(s.$c-legend, "radius") - map.get(s.$c-legend, "border"));
                border-bottom-left-radius: calc(map.get(s.$c-legend, "radius") - map.get(s.$c-legend, "border"));
            }

            > span {
                flex-grow: 1;

                .icon {
                    margin: 0 0.5ch;
                }
            }

            &.warning {
                background-color: map.get(c.$c-legend, "warning", "background");
                color: map.get(c.$c-legend, "warning", "surface");
            }

            &.error {
                background-color: map.get(c.$c-legend, "error", "background");
                color: map.get(c.$c-legend, "error", "surface");
            }

            &.success {
                background-color: map.get(c.$c-legend, "success", "background");
                color: map.get(c.$c-legend, "success", "surface");
            }
        }
    }
}
</style>
