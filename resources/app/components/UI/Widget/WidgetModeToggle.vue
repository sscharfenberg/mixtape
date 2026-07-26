<script setup lang="ts">
/******************************************************************************
 * WidgetModeToggle
 * The mode switch in a music Widget's title strip — one segmented pill of two
 * or three icon options, driven by the `modes` prop (each widget passes the
 * modes it supports, in display order). The native radios are visually hidden
 * but stay focusable, so native arrow keys still move selection; each label is a
 * clickable segment. Only the checked segment carries brand colour (the
 * SiteMenuLinks current-link highlight) so it's unmistakable; the unselected
 * segments are muted grey. Each swaps its fill/icon on hover for feedback. The
 * icons carry no visible text, so each segment is wrapped in a Tooltip that
 * briefly explains what the mode ranks by (see `tip`) — on hover/focus; the
 * input+label stay adjacent inside the wrapper so the `input:checked/:focus + &`
 * selectors keep matching. Two-way binds the active mode via v-model; `name`
 * groups the radios, so every toggle on the page needs a unique one.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import type { WidgetMode } from "Types/music";

const { t } = useI18n();

const props = defineProps<{
    /** Unique radio-group name — distinct per widget so the page's toggles don't collide. */
    name: string;
    /** Modes to render as segments, left-to-right (e.g. `['latest','popular','random']`); a widget passes only the ones it supports. */
    modes: WidgetMode[];
    /** What the "popular" segment ranks by — plays (songs) or total file duration (artists/genres) — so its tooltip can say which. */
    popularBy?: "plays" | "duration";
}>();

const mode = defineModel<WidgetMode>({ required: true });

/**
 * The single source of truth mapping each mode to its sprite icon, so consumers
 * pick modes by name and the icon stays consistent everywhere: recent = latest,
 * hot = popular, shuffle = random.
 */
const ICONS: Record<WidgetMode, string> = {
    latest: "recent",
    popular: "hot",
    random: "shuffle",
};

/**
 * The explanatory tooltip text for a mode — a short phrase of what it ranks by,
 * since the icon alone doesn't convey it. "popular" branches on `popularBy`
 * because it means most-played for songs but most-minutes for artists/genres.
 */
const tip = (m: WidgetMode): string => {
    if (m === "popular") return t(`music.mode.tip.popular_${props.popularBy ?? "plays"}`);
    return t(`music.mode.tip.${m}`);
};
</script>

<template>
    <span class="widget-mode-toggle" role="radiogroup" :aria-label="t('music.mode.label')">
        <tooltip v-for="m in modes" :key="m" :text="tip(m)">
            <input :id="`${name}-${m}`" v-model="mode" type="radio" :name="name" :value="m" />
            <label :for="`${name}-${m}`" class="widget-mode-toggle__item" :aria-label="t(`music.mode.${m}`)">
                <icon :name="ICONS[m]" :size="2" />
            </label>
        </tooltip>
    </span>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.widget-mode-toggle {
    display: inline-flex;

    // one pill: a single frame with the two segments flush inside it. overflow
    // clips each segment's fill to the frame's rounded corners; the frame border
    // is the SiteMenuLinks normal-link border (`surface`); the frame fill is the
    // unselected/normal background, which the left (unselected) segment shows
    // through. Sits at the trailing edge of the widget title flex row.
    overflow: hidden;
    border: map.get(s.$c-widget-mode-toggle, "border") solid map.get(c.$c-widget-mode-toggle, "surface");
    margin-inline-start: auto;

    background: map.get(c.$c-widget-mode-toggle, "background");
    border-radius: map.get(s.$c-widget-mode-toggle, "radius");

    font-size: 0.75em;
    font-weight: 400;

    // radios stay in the DOM but visually hidden — still focusable/tabbable for
    // keyboard + screen-reader users (unlike display:none). Native arrow keys move
    // focus AND selection within the group, which is what drives the mode change.
    input {
        position: absolute;

        overflow: hidden;

        width: 1px;
        height: 1px;
        padding: 0;
        border: 0;
        margin: -1px;
        clip-path: inset(50%);

        white-space: nowrap;
    }

    &__item {
        display: inline-flex;
        align-items: center;

        padding: map.get(s.$c-widget-mode-toggle, "padding");

        color: map.get(c.$c-widget-mode-toggle, "surface");

        line-height: 1;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                color ti.$c-widget-mode-toggle linear,
                background-color ti.$c-widget-mode-toggle linear;
        }

        // hover on the UNSELECTED segment — swap the muted grey fill and icon for feedback.
        &:hover {
            background-color: map.get(c.$c-widget-mode-toggle, "surface");
            color: map.get(c.$c-widget-mode-toggle, "background");
        }

        // visible keyboard focus ring (:focus-visible → keyboard only, not mouse
        // clicks); inset so the frame's overflow:hidden doesn't clip it.
        // currentcolor = the segment's own accent, so it reads on either state.
        input:focus-visible + & {
            outline: 2px solid currentcolor;
            outline-offset: -2px;
        }

        // checked segment — the SiteMenuLinks "current link" highlight; same swap on hover.
        input:checked + & {
            background-color: map.get(c.$c-widget-mode-toggle, "background-selected");
            color: map.get(c.$c-widget-mode-toggle, "surface-selected");
        }

        input:checked + &:hover {
            background-color: map.get(c.$c-widget-mode-toggle, "surface-selected");
            color: map.get(c.$c-widget-mode-toggle, "background-selected");
        }
    }
}
</style>
