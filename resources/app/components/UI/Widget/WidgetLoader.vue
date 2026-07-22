<script setup lang="ts">
/******************************************************************************
 * WidgetLoader
 * A Widget's loading overlay: a translucent scrim over the whole card with a
 * centered LoadingSpinner, announced to assistive tech via role="status".
 * Rendered by Widget while its `loading` prop is set; it fades in when motion
 * is allowed.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

const { t } = useI18n();
</script>

<template>
    <div class="widget__loader" role="status" :aria-label="t('common.loading')">
        <loading-spinner :size="4" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.widget__loader {
    display: flex;
    position: absolute;
    inset: 0;
    z-index: z.$c-widget;
    align-items: center;
    justify-content: center;

    background-color: map.get(c.$c-widget, "loader-overlay");
    backdrop-filter: blur(2px);
    color: map.get(c.$c-widget, "loader-spinner");

    @media (prefers-reduced-motion: no-preference) {
        animation: widget-loader-in ti.$c-widget ease-out;
    }
}

@media (prefers-reduced-motion: no-preference) {
    @keyframes widget-loader-in {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }
}
</style>
