<script setup lang="ts">
/******************************************************************************
 * WidgetSkeleton
 * A placeholder for a Widget's body while its data is still loading — the
 * deferred-first-load case (vs WidgetLoader, which is the busy-over-existing-
 * content overlay for refreshes). Goes in the Widget's default slot in place of
 * the real content; reserves realistic height so the subgrid bands don't jump
 * when the content lands. The shimmer animates only when motion is allowed;
 * announced to assistive tech via role="status".
 *
 * TWO SHAPES, because a placeholder only does its job if it is the shape of what
 * it replaces. `text` is a stack of plain bars, right for a body of prose or
 * tiles. `entries` mirrors a WidgetList row — a filled block holding a name line
 * and a row of pips — and exists because the four music widgets got exactly that
 * and the bars stopped lining up: four 14px bars stood in for four 65px blocks,
 * so the card collapsed to a third of its height on every refresh and snapped
 * back when the data arrived. The point of a skeleton is that nothing moves.
 *
 * The `entries` variant reads WidgetList's own size tokens rather than minting
 * its own copies, which is what keeps the two the same height by construction
 * instead of by two numbers someone has to remember to change together.
 *****************************************************************************/
import { useI18n } from "vue-i18n";

const { t } = useI18n();

/** Which shape the placeholder takes — see the banner. */
export type SkeletonVariant = "text" | "entries";

withDefaults(
    defineProps<{
        /** Number of placeholder rows — the line (or entry) count of the eventual content. */
        rows?: number;
        /** `text` for prose/tiles, `entries` to mirror a WidgetList. */
        variant?: SkeletonVariant;
    }>(),
    {
        rows: 3,
        variant: "text"
    }
);
</script>

<template>
    <div :class="['widget-skeleton', `widget-skeleton--${variant}`]" role="status" :aria-label="t('common.loading')">
        <template v-if="variant === 'entries'">
            <div v-for="row in rows" :key="row" class="widget-skeleton__entry">
                <div class="widget-skeleton__bar widget-skeleton__bar--name" />
                <div class="widget-skeleton__pips">
                    <!-- Two pips, not three: every widget shows at least two, and a stand-in
                         that promised three would leave a gap on the albums and songs cards
                         whenever a tag is missing. -->
                    <div class="widget-skeleton__bar widget-skeleton__bar--pip" />
                    <div class="widget-skeleton__bar widget-skeleton__bar--pip" />
                </div>
            </div>
        </template>
        <div v-for="row in rows" v-else :key="row" class="widget-skeleton__bar" />
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

    // The entries variant takes the LIST's gap, not the skeleton's own, so the rows sit
    // exactly where the real entries will (see the banner on reading its tokens).
    &--entries {
        gap: map.get(s.$c-widget-list, "gap");
    }

    // One stand-in entry: the same fill, padding and rounding a real one has, so the swap
    // changes what is inside the block rather than the block itself.
    &__entry {
        display: flex;
        flex-direction: column;

        padding: map.get(s.$c-widget-list, "item-padding");
        gap: map.get(s.$c-widget-list, "item-gap");

        background-color: map.get(c.$c-widget-list, "background");
        border-radius: map.get(s.$c-widget-list, "radius");
    }

    // The trailing bar is shorter, like the last line of a paragraph — a prose idea, so it
    // is scoped to that variant. Applied globally it also caught the last PIP of every
    // entry, stretching it across most of the row.
    &--text &__bar:last-child:not(:only-child) {
        width: 60%;
    }

    // The list renders its pips at 0.85em; matching it here is what makes the `1lh` on the
    // pip bar resolve to the same line box a real pip has.
    &__pips {
        display: flex;

        gap: map.get(s.$c-widget-list, "pip-gap");

        font-size: 0.85em;
    }

    &__bar {
        height: map.get(s.$c-widget, "skeleton-bar");

        background-color: map.get(c.$c-widget, "skeleton-base");
        border-radius: map.get(s.$c-widget, "skeleton-radius");

        // A name runs most of the width but rarely all of it. `1lh` — exactly one line box —
        // rather than the paragraph bar's fixed height, because that is what it stands in
        // for: a single line of the entry's own text.
        &--name {
            width: 70%;
            height: 1lh;
        }

        // Pips are short pills, one line tall plus the vertical padding a real pip carries
        // (2 × the 0.05rem in the list's `pip-padding`). Written out because a shorthand
        // token cannot be halved in Sass — if that padding changes, this follows it.
        &--pip {
            width: 4.5em;
            height: calc(1lh + 0.1rem);
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
