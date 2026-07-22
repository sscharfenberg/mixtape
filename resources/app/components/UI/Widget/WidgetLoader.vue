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
    // Delayed reveal: hidden for `loader-delay`, then shown. A fast op unmounts
    // WidgetLoader before the delay elapses, so it never flashes — only a
    // genuinely slow one reveals it. The 0s step keeps reduced-motion users
    // flash-free too; the no-preference block upgrades that step to a fade.
    display: flex;
    position: absolute;
    inset: 0;
    z-index: z.$c-widget;
    align-items: center;
    justify-content: center;
    opacity: 0;

    background-color: map.get(c.$c-widget, "loader-overlay");
    backdrop-filter: blur(2px);
    color: map.get(c.$c-widget, "loader-spinner");

    animation: widget-loader-reveal 0s linear map.get(ti.$c-widget, "loader-delay") forwards;

    @media (prefers-reduced-motion: no-preference) {
        animation:
            widget-loader-fade map.get(ti.$c-widget, "loader-fade") ease-out
            map.get(ti.$c-widget, "loader-delay") forwards;
    }
}

// instant reveal once the delay elapses (reduced-motion / unknown-preference path)
@keyframes widget-loader-reveal {
    to {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: no-preference) {
    @keyframes widget-loader-fade {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }
}
</style>
