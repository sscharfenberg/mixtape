<script setup lang="ts">
/******************************************************************************
 * Breadcrumb
 * The breadcrumb trail, ported from cantrip.me. Mounted ONCE in FullLayout and
 * fed by the module-level useBreadcrumbs store, so a page declares its path in
 * `<script setup>` and this renders it — see Composables/useBreadcrumbs for why
 * the trail is declared rather than derived from the URL.
 *
 * Renders nothing at all when the trail is empty (the site root, which *is* the
 * home chip, and any page that hasn't declared a path). Otherwise: a fixed home
 * chip, then one chip per crumb — a <Link> while the crumb has an `href`, a
 * plain <span aria-current="page"> when it doesn't, which is how the last crumb
 * (the page you are on) is normally declared.
 *
 * Each chip is drawn as a skewed arrow by two pseudo-elements (top half skewed
 * one way, bottom half the other) rather than by a chevron glyph between the
 * items, so the trail reads as one continuous ribbon. On narrow screens all of
 * it collapses to the single *parent* crumb with its arrow flipped: at that
 * width the trail's only real job is "go back one level", so a top-level page
 * (one crumb, no parent) shows nothing there.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

const { t } = useI18n();
const { crumbs } = useBreadcrumbs();

/**
 * Resolve a breadcrumb's displayed text: prefer the raw `label` if set,
 * otherwise translate `labelKey` with any supplied interpolation params. Raw
 * wins because it carries the values that are never in the catalog — a song's
 * title, an album name.
 */
function resolveLabel(crumb: (typeof crumbs.value)[number]): string {
    return crumb.label ?? t(crumb.labelKey ?? "", crumb.params ?? {});
}
</script>

<template>
    <nav v-if="crumbs.length" class="breadcrumb" :aria-label="t('breadcrumb.nav')">
        <Link href="/" class="breadcrumb__item" :aria-label="t('breadcrumb.home')"
            ><span><icon name="home" /></span
        ></Link>
        <template v-for="(crumb, index) in crumbs" :key="crumb.labelKey ?? crumb.label">
            <Link
                v-if="crumb.href"
                :href="crumb.href"
                :class="['breadcrumb__item', { 'breadcrumb__item--parent': index === crumbs.length - 2 }]"
            >
                <span><icon v-if="crumb.icon" :name="crumb.icon" />{{ resolveLabel(crumb) }}</span>
            </Link>
            <span v-else class="breadcrumb__item" aria-current="page">
                <span><icon v-if="crumb.icon" :name="crumb.icon" />{{ resolveLabel(crumb) }}</span>
            </span>
        </template>
    </nav>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    margin: 0 0 1lh 6px;
    gap: 0.25rem;

    &__item {
        position: relative;

        gap: 1ch;

        color: map.get(c.$c-breadcrumb, "surface");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-breadcrumb linear;
        }

        // use pseudo elements to form an arrow (right side)
        // with the corresponding notch (left side)
        &::before,
        &::after {
            display: inline-block;
            position: absolute;
            left: 0;
            z-index: z.$c-breadcrumb;

            width: 100%;
            height: 50%;
            border-right: map.get(s.$c-breadcrumb, "border") solid map.get(c.$c-breadcrumb, "border");
            border-left: map.get(s.$c-breadcrumb, "border") solid map.get(c.$c-breadcrumb, "border");

            background-color: map.get(c.$c-breadcrumb, "background");

            content: "";

            @media (prefers-reduced-motion: no-preference) {
                transition: background-color ti.$c-breadcrumb linear;
            }
        }

        &::before {
            top: 0;

            border-top: map.get(s.$c-breadcrumb, "border") solid map.get(c.$c-breadcrumb, "border");
            transform: skew(30deg);
        }

        &::after {
            bottom: 0;

            border-bottom: map.get(s.$c-breadcrumb, "border") solid map.get(c.$c-breadcrumb, "border");
            transform: skew(-30deg);
        }

        > span {
            display: flex;
            align-items: center;

            padding: map.get(s.$c-breadcrumb, "padding");
            gap: 1ch;

            line-height: map.get(s.$c-breadcrumb, "line-height");
        }

        // the home chip is the one crumb whose fill is painted on the <span>
        // (a box-shadow squares off its left edge, which the skewed pseudos
        // can't). that span has no transition, so the pseudos must not have one
        // either — otherwise the two halves of the same chip would recolour at
        // different speeds on hover.
        &:first-child {
            @media (prefers-reduced-motion: no-preference) {
                transition: none;

                &::before,
                &::after {
                    transition: none;
                }
            }

            > span {
                $border: map.get(s.$c-breadcrumb, "border");
                $outline: map.get(c.$c-breadcrumb, "border");

                width: calc(100% - $border);

                margin-left: -6px; // magic number because of skew(30deg).

                background-color: map.get(c.$c-breadcrumb, "background");
                box-shadow:
                    inset #{$border} 0 0 0 $outline,
                    inset 0 #{$border} 0 0 $outline,
                    inset 0 -#{$border} 0 0 $outline;
            }
        }

        // Skip the last crumb (current page, never a link) AND any non-link
        // crumb — a crumb rendered as a <span> has no href, so a hover
        // affordance would promise a navigation that can't happen.
        &:not(:last-child, span):hover {
            color: map.get(c.$c-breadcrumb, "surface-hover");

            &::before,
            &::after {
                background-color: map.get(c.$c-breadcrumb, "background-hover");
            }
        }

        &:first-child:hover > span {
            background-color: map.get(c.$c-breadcrumb, "background-hover");
        }

        &:last-child {
            margin-left: 1px;

            color: map.get(c.$c-breadcrumb, "surface-current");

            &::before,
            &::after {
                background-color: map.get(c.$c-breadcrumb, "background-current");
            }
        }

        // mobile: show only breadcrumb__item--parent, hide everything else.
        // also, have arrow point in the other direction, since it is a "go back" link
        &:not(.breadcrumb__item--parent) {
            display: none;

            @include m.mq("landscape") {
                display: block;
            }
        }

        &--parent {
            &::before {
                transform: skew(-30deg);
            }

            &::after {
                transform: skew(30deg);
            }

            @include m.mq("landscape") {
                &::before {
                    transform: skew(30deg);
                }

                &::after {
                    transform: skew(-30deg);
                }
            }
        }
    }
}
</style>
