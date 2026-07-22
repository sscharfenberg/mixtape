<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import AppHeaderLogo from "Components/Landmarks/Header/AppHeaderLogo.vue";
import AppHeaderTitle from "Components/Landmarks/Header/AppHeaderTitle.vue";
import HeaderNavigation from "Components/Landmarks/Header/HeaderNavigation.vue";

// Publishes the header's real rendered height as `--app-header-height` on the
// root element, so affixed chrome further down the page (e.g. StickyNav) can
// pin itself just below the header instead of guessing a height that drifts
// across breakpoints.
const headerRef = ref<HTMLElement | null>(null);

onMounted(() => {
    const setHeightVar = (): void => {
        if (headerRef.value) {
            document.documentElement.style.setProperty("--app-header-height", `${headerRef.value.getBoundingClientRect().height}px`);
        }
    };
    setHeightVar();

    const observer = new ResizeObserver(setHeightVar);
    if (headerRef.value) observer.observe(headerRef.value);

    onUnmounted(() => observer.disconnect());
});
</script>

<template>
    <header ref="headerRef" class="app-header">
        <div class="inner">
            <app-header-logo />
            <app-header-title />
            <header-navigation />
        </div>
    </header>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

.app-header {
    position: sticky;
    top: 0;
    z-index: z.$c-header;

    background-color: map.get(c.$c-frosted-glass, "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$c-frosted-glass, "surface");

    @include m.mqset(
        "padding",
        #{map.get(s.$c-app, "padding", "base") * 0.5 map.get(s.$c-app, "padding", "base")},
        #{map.get(s.$c-app, "padding", "portrait") * 0.5 map.get(s.$c-app, "padding", "portrait")},
        #{map.get(s.$c-app, "padding", "landscape") * 0.5 map.get(s.$c-app, "padding", "landscape")},
        #{map.get(s.$c-app, "padding", "desktop") * 0.5 map.get(s.$c-app, "padding", "desktop")}
    );

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

        @include m.mqset(
            "gap",
            #{map.get(s.$c-header, "gap", "base")},
            #{map.get(s.$c-header, "gap", "portrait")},
            #{map.get(s.$c-header, "gap", "landscape")},
            #{map.get(s.$c-header, "gap", "desktop")}
        );
    }
}
</style>
