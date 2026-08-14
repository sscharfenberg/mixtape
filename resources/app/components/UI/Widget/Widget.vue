<script setup lang="ts">
/******************************************************************************
 * Widget
 * A content card assembled from the WidgetTitle / WidgetBody / WidgetFooter
 * parts, used to lay out the browse pages. Slots: #title (optional header
 * strip), default (the body), #footer (optional). While `loading` is true a
 * WidgetLoader overlay covers the whole card. When `refresh` is set, the footer
 * shows a refresh button; while its partial reload is in flight the body is
 * swapped for a WidgetSkeleton (the footer emits `refreshing`), so the card
 * shows placeholders until the fresh data lands — held at the height the body
 * already had, because no fixed skeleton can know that an entry wrapped (see
 * `onRefreshing`). Drop several inside a WidgetGroup for the responsive grid;
 * set `wide` to span two of its columns (from the landscape breakpoint up,
 * where two tracks fit).
 *****************************************************************************/
import type { ComponentPublicInstance } from "vue";
import { ref, useTemplateRef } from "vue";
import WidgetBody from "./WidgetBody.vue";
import WidgetFooter from "./WidgetFooter.vue";
import WidgetLoader from "./WidgetLoader.vue";
import WidgetSkeleton, { type SkeletonVariant } from "./WidgetSkeleton.vue";
import WidgetTitle from "./WidgetTitle.vue";

withDefaults(
    defineProps<{
        /** Show the loading overlay (a centered spinner) over the whole card. */
        loading?: boolean;
        /** Span two grid columns in a WidgetGroup (from the "landscape" breakpoint up, where two tracks fit). */
        wide?: boolean;
        /** Inertia prop key for this widget's data; when set the footer shows a refresh button that partial-reloads it. */
        refresh?: string;
        /** Centre the body content vertically within its band (it stays full-width) — for a body shorter than the row's tallest card. */
        centered?: boolean;
        /**
         * Shape of the refresh placeholder. `entries` for a body that is a WidgetList, so the
         * card keeps its height across the swap; the default suits prose or tiles.
         */
        skeleton?: SkeletonVariant;
    }>(),
    {
        loading: false,
        wide: false,
        skeleton: "text"
    }
);

/** True while the footer's refresh partial-reload is in flight; swaps the body for the skeleton. */
const refreshing = ref(false);

/** The body, so its height can be read before the skeleton replaces what is in it. */
const body = useTemplateRef<ComponentPublicInstance>("body");

/** The height the body is held at while refreshing, or null when it is free to size itself. */
const heldHeight = ref<string | null>(null);

/**
 * Start or end a refresh, holding the body at the height it already had.
 *
 * WHY A MEASUREMENT RATHER THAN A TALLER SKELETON. The skeleton reserves four entries of a
 * fixed height, which is right for almost every row — but an entry is only that tall when its
 * pips fit on one line, and a long credit wraps them onto a second. Measured on the E2E
 * fixture: the Albums card's fourth entry is 89px where its neighbours are 65px, so its
 * skeleton came up 24px short, the card shrank mid-refresh, and every card sharing its subgrid
 * row shrank with it. No fixed skeleton can know that in advance — the height is a fact about
 * the DATA — so the only honest answer is to remember what the body actually was.
 *
 * MEASURED IN THIS HANDLER, synchronously, which is the part that matters: the footer emits
 * before anything re-renders, so the body still holds the real content here. A watcher on
 * `refreshing` would run after the swap and measure the skeleton.
 *
 * `min-height`, not `height`: a skeleton is never taller than what it stands in for today, but
 * a floor cannot clip one that is.
 */
const onRefreshing = (inFlight: boolean): void => {
    const element = body.value?.$el as HTMLElement | undefined;

    // Released on the way out, so the card settles to whatever the fresh data needs — a
    // "random" refresh legitimately brings a shorter list, and holding the old height would
    // leave a strip of empty card under it for as long as the page lived.
    heldHeight.value = inFlight && element ? `${Math.round(element.getBoundingClientRect().height)}px` : null;
    refreshing.value = inFlight;
};
</script>

<template>
    <div class="widget" :class="{ 'widget--wide': wide }">
        <widget-title v-if="$slots.title"><slot name="title" /></widget-title>
        <widget-body ref="body" :centered="centered" :style="heldHeight ? { minHeight: heldHeight } : undefined">
            <widget-skeleton v-if="refreshing" :rows="4" :variant="skeleton" />
            <slot v-else />
        </widget-body>
        <widget-footer v-if="$slots.footer || refresh" :refresh="refresh" @refreshing="onRefreshing">
            <slot name="footer" />
        </widget-footer>
        <widget-loader v-if="loading" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

.widget {
    // A subgrid card: it occupies the group's title / body / footer row bands
    // (grid-row: span 3) and subgrids into them (grid-template-rows: subgrid), so
    // those bands share a height across a row and every footer lines up. row-gap 0
    // keeps the sections flush; only the group gap spaces cards apart. Solid
    // surface — the browse pages sit on a solid background, so frosted glass would
    // blur nothing here.
    display: grid;
    position: relative; // positioning context for the WidgetLoader overlay
    grid-template-rows: subgrid;
    grid-row: span 3;
    isolation: isolate; // contain the loader overlay's z-index to this card

    overflow: hidden; // clip the title strip to the card's rounded corners
    border: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "border");
    row-gap: 0;

    background-color: map.get(c.$c-widget, "background");
    color: map.get(c.$c-widget, "surface");
    border-radius: map.get(s.$c-widget, "radius");

    /* A CARD WITH NO FOOTER GIVES ITS BODY THE FOOTER'S BAND. The three bands are
       shared across a row so that every footer lines up, which means a footerless card was
       reserving an empty strip as tall as its neighbours' footers — visible as a band of blank
       card under its content, and the reason the stats card looked half-used however much its
       tiles grew. Spanning the body over both bands hands that height to the content instead.

       `:has()` rather than a prop, because the footer already decides its own existence
       (`v-if="$slots.footer || refresh"` in the template) and a prop would be the same fact
       stated twice. WidgetFooter's root carries this component's scope id — a child component's
       root gets its parent's — so the selector reaches it. */
    &:not(:has(.widget__footer)) .widget__body {
        grid-row: 2 / span 2;
    }

    // opt-in `wide`: span two grid columns in a WidgetGroup. Gated to the
    // "landscape" breakpoint and up, where the group reliably fits two of its
    // 220px tracks — below that the group is a single column, so spanning two
    // would overflow. `grid-auto-flow: dense` on the group backfills the gaps a
    // wide card leaves.
    &--wide {
        @include m.mq("landscape") {
            grid-column: span 2;
        }
    }
}
</style>
