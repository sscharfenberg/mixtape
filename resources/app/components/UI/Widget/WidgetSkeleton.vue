<script setup lang="ts">
/******************************************************************************
 * WidgetSkeleton
 * A placeholder for a Widget's body while its data is still loading — the
 * deferred-first-load case (vs WidgetLoader, which is the busy-over-existing-
 * content overlay for refreshes). Goes in the Widget's default slot in place of
 * the real content; renders `rows` shimmer bars that reserve realistic height,
 * so the subgrid bands don't jump when the content lands. The shimmer animates
 * only when motion is allowed; announced to assistive tech via role="status".
 *****************************************************************************/
import { useI18n } from "vue-i18n";

const { t } = useI18n();

withDefaults(
    defineProps<{
        /** Number of placeholder bars — roughly the line count of the eventual content. */
        rows?: number;
    }>(),
    {
        rows: 3
    }
);
</script>

<template>
    <div class="widget-skeleton" role="status" :aria-label="t('common.loading')">
        <div v-for="row in rows" :key="row" class="widget-skeleton__bar" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.widget-skeleton {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-widget, "skeleton-gap");

    &__bar {
        height: map.get(s.$c-widget, "skeleton-bar");

        background-color: map.get(c.$c-widget, "skeleton-base");
        border-radius: map.get(s.$c-widget, "skeleton-radius");

        // the trailing bar is shorter, like the last line of a paragraph
        &:last-child:not(:only-child) {
            width: 60%;
        }

        // shimmer sweep — motion only; a static bar under reduced motion is fine
        // (unlike a frozen spinner, it doesn't read as broken).
        @media (prefers-reduced-motion: no-preference) {
            background-image: linear-gradient(
                90deg,
                transparent 0%,
                map.get(c.$c-widget, "skeleton-sheen") 50%,
                transparent 100%
            );
            background-repeat: no-repeat;
            background-size: 200% 100%;

            animation: widget-skeleton-shimmer map.get(ti.$c-widget, "skeleton") linear infinite;
        }
    }
}

@media (prefers-reduced-motion: no-preference) {
    @keyframes widget-skeleton-shimmer {
        from {
            background-position: 200% 0;
        }

        to {
            background-position: -200% 0;
        }
    }
}
</style>
