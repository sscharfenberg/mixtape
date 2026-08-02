<script setup lang="ts">
/******************************************************************************
 * Breadcrumb
 * The breadcrumb trail, ported from cantrip.me. Mounted ONCE in FullLayout,
 * which hands it the trail the current page declared via useBreadcrumbs (an
 * Inertia layout prop — see that composable for why the trail is declared by the
 * page rather than derived from the URL, and why layout props are what keep it
 * on screen for the whole of a navigation).
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
 *
 * The label sits in a `__label` span of its own so an over-long crumb — a song
 * title, and they get long — can be truncated with an ellipsis instead of
 * wrapping the ribbon onto a second line. That needs an element: the chip's
 * inner span is a flex container, and `text-overflow` has nothing to act on when
 * the text is an anonymous flex item. Nothing is lost by cutting it, because the
 * page's own heading carries the full string.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs";

defineProps<{
    /** The trail to draw, in display order — the last item is the page you are on. Empty renders nothing. */
    crumbs: BreadcrumbItem[];
}>();

const { t } = useI18n();

/**
 * Resolve a breadcrumb's displayed text: prefer the raw `label` if set,
 * otherwise translate `labelKey` with any supplied interpolation params. Raw
 * wins because it carries the values that are never in the catalog — a song's
 * title, an album name.
 */
function resolveLabel(crumb: BreadcrumbItem): string {
    return crumb.label ?? t(crumb.labelKey ?? "", crumb.params ?? {});
}
</script>

<template>
    <nav v-if="crumbs.length" class="breadcrumb" :aria-label="t('breadcrumb.nav')">
        <Link href="/" class="breadcrumb__item" prefetch :aria-label="t('breadcrumb.home')"
            ><span><icon name="home" /></span
        ></Link>
        <template v-for="(crumb, index) in crumbs" :key="crumb.labelKey ?? crumb.label">
            <Link
                v-if="crumb.href"
                :href="crumb.href"
                prefetch
                :class="['breadcrumb__item', { 'breadcrumb__item--parent': index === crumbs.length - 2 }]"
            >
                <span
                    ><icon v-if="crumb.icon" :name="crumb.icon" /><span class="breadcrumb__label">{{
                        resolveLabel(crumb)
                    }}</span></span
                >
            </Link>
            <span v-else class="breadcrumb__item" aria-current="page">
                <span
                    ><icon v-if="crumb.icon" :name="crumb.icon" /><span class="breadcrumb__label">{{
                        resolveLabel(crumb)
                    }}</span></span
                >
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

/* `nowrap`, and the reason is the arrows. A wrapped chip takes its two skewed halves
   with it, so an over-long crumb didn't just move down a line — it left a lone
   arrowhead sitting under the ribbon, which reads as a rendering glitch rather than as
   a continuation. The trail stays on one line and the long crumb is ellipsised instead
   (`__label` below); the only trail that can be long is one whose last crumb is a
   title, and that title is repeated in full in the page's heading right underneath.

   Not `overflow: hidden` on the nav as a backstop, by the way: the chips' skewed
   pseudo-elements deliberately reach past their own box (hence the home chip's -6px),
   so clipping here would shave the last arrowhead off. */
.breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;

    margin: 0 0 1lh 6px;
    gap: 0.25rem;

    /* `flex-shrink: 0` by default and opt-in per crumb below. Shrink is distributed in
       proportion to base width, so left to itself flexbox would also shave a few pixels
       off "Musik" and "Songs" and ellipsise words that fit perfectly well — only the
       crumbs that carry *data* give ground. `min-width: 0` is what makes shrinking
       possible at all for those: a flex item won't go below its content width otherwise,
       and an unbreakable nowrap label would just push the row wider. */
    &__item {
        position: relative;
        flex-shrink: 0;

        min-width: 0;
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
            // The current page: a title rather than a catalog string, so it is the crumb
            // that absorbs whatever width the fixed chips leave (see __item).
            flex-shrink: 1;

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
            // Shrinks too, because below `landscape` this is the ONLY chip on screen: its
            // label can be an album or song title, and with nothing beside it to give way
            // there is no one else to take the deficit.
            flex-shrink: 1;

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

    /* Where the cutting actually happens. `nowrap` first — without it the text wraps
       inside the chip and never overflows, so there is nothing for `text-overflow` to
       trim, and a two-line chip stretches its 50%-height arrow halves into a shape twice
       the size of every other chip's. `min-width: 0` again, because this span is itself a
       flex item (of the chip's inner span, beside the icon) and a nowrap string is
       unbreakable, i.e. its min-content width is the whole label.

       The icon needs no protection from the shrink: Components/UI/Icon sets
       `flex: 0 0 var(--icon-size)` on every <svg>, so it holds its size and only the text
       gives way. */
    &__label {
        overflow: hidden;

        min-width: 0;

        white-space: nowrap;

        text-overflow: ellipsis;
    }
}
</style>
