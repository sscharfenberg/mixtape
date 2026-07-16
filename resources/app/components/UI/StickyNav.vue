<script setup lang="ts">
/******************************************************************************
 * StickyNav
 * A jump-link nav for long settings pages (ported from cantrip.me): a row of
 * anchor links to `#<id>` sections, affixed to the top of the viewport once
 * scrolled past. `useStickyNav` drives two behaviours via IntersectionObserver
 * — `isStuck` (switches to the opaque "stuck" look once the nav is actually
 * pinned) and `activeSection` (highlights whichever section's top edge is
 * currently in view), so clicking a link or scrolling both keep the nav in
 * sync with the page.
 *****************************************************************************/
import { computed } from "vue";
import { useStickyNav } from "Composables/useStickyNav";

export type StickyNavItem = {
    id: string;
    label: string;
};

const props = defineProps<{
    items: StickyNavItem[];
    /** Accessible name for the <nav> landmark. Defaults to a German label. */
    label?: string;
}>();

const navLabel = computed(() => props.label ?? "Sprungnavigation");
const { sentinel, isStuck, activeSection } = useStickyNav(props.items.map(i => i.id));
</script>

<template>
    <div ref="sentinel" aria-hidden="true" class="sticky-nav__sentinel" />
    <nav class="sticky-nav" :class="{ 'sticky-nav--sticky': isStuck }" :aria-label="navLabel">
        <a v-for="item in items" :key="item.id" :href="`#${item.id}`" :class="{ active: activeSection === item.id }">{{
            item.label
        }}</a>
    </nav>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.sticky-nav__sentinel {
    height: 0;

    pointer-events: none;
}

.sticky-nav {
    display: flex;
    position: sticky;
    top: var(--app-header-height, 0);
    z-index: z.$c-sticky-nav;
    flex-wrap: wrap;

    padding: map.get(s.$c-sticky-nav, "padding");
    margin-bottom: 1lh;
    gap: 1ch;

    background-color: map.get(c.$c-sticky-nav, "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$c-sticky-nav, "surface");
    border-radius: map.get(s.$c-sticky-nav, "radius");

    &::before {
        position: absolute;
        inset: 0;

        border: map.get(s.$c-sticky-nav, "border") solid transparent;

        background: linear-gradient(
                to bottom right,
                map.get(c.$c-sticky-nav, "border-from"),
                map.get(c.$c-sticky-nav, "border-to")
            )
            border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";

        pointer-events: none;
    }

    &--sticky {
        background-color: map.get(c.$c-sticky-nav, "background-sticky");

        border-top-left-radius: 0;
        border-top-right-radius: 0;

        &::before {
            border-top-width: 0;

            background: map.get(c.$c-sticky-nav, "border-sticky");
        }
    }

    > a {
        padding: map.get(s.$c-sticky-nav, "link-padding");

        background-color: map.get(c.$c-sticky-nav, "link-background");
        color: map.get(c.$c-sticky-nav, "link-surface");
        border-radius: map.get(s.$c-sticky-nav, "link-radius");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-sticky-nav linear,
                color ti.$c-sticky-nav linear;
        }

        &:hover {
            background-color: map.get(c.$c-sticky-nav, "link-background-hover");
            color: map.get(c.$c-sticky-nav, "link-surface-hover");
        }

        &.active {
            background-color: map.get(c.$c-sticky-nav, "link-background-active");
            color: map.get(c.$c-sticky-nav, "link-surface-active");
        }
    }
}
</style>
