<script setup lang="ts">
import AppHeaderLogo from "Components/Landmarks/Header/AppHeaderLogo.vue";
import AppHeaderTitle from "Components/Landmarks/Header/AppHeaderTitle.vue";
</script>

<template>
    <header class="app-header">
        <div class="inner">
            <app-header-logo />
            <app-header-title />
        </div>
    </header>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

.app-header {
    position: sticky;
    top: 0;
    z-index: z.$c-header;

    background-color: map.get(c.$c-frosted-glass, "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$c-frosted-glass, "surface");

    &::before {
        position: absolute;
        inset: 0;
        z-index: -1;

        border-bottom: map.get(s.$c-frosted-glass, "border") solid transparent;

        background: linear-gradient(
                to bottom right,
                map.get(c.$c-frosted-glass, "border-from"),
                map.get(c.$c-frosted-glass, "border-to")
            )
            border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";
    }

    .inner {
        display: flex;
        align-items: center;

        max-width: map.get(s.$c-header, "max");
        margin: 0 auto;
        gap: map.get(s.$c-header, "gap");
    }
}
</style>
