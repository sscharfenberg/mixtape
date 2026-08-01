<script setup lang="ts">
/******************************************************************************
 * TabbedNavigation
 * A tab strip over switchable panels, one named slot per tab. Ported from the
 * legacy app's Components/TabbedNavigation, where the artist page used it for the
 * same two tabs this one does — but rebuilt on three counts, each a fix rather
 * than a preference:
 *
 * 1. THE ARIA TABS PATTERN, not radio inputs. Legacy built the strip from
 *    `<input type="radio">` + `<label>` and then hid the inputs with
 *    `display: none` — which takes them out of the accessibility tree AND out of
 *    the tab order, so the whole control was unreachable by keyboard and silent
 *    to a screen reader. Here each tab is a real `role="tab"` button inside a
 *    `role="tablist"`, wired to its panel with aria-controls/aria-labelledby, so
 *    the relationship between a tab and the panel it reveals is actually spoken
 *    ("tab, 2 of 2, selected"). A radiogroup cannot express that.
 * 2. THE PANELS BELONG TO THE COMPONENT. Legacy handed the whole panel area to
 *    one default slot and left the page to hide the inactive half itself with
 *    `v-show="currentTabIndex === 0"` — so every consumer re-implemented the
 *    visibility rule, and none of them could wire up the ARIA. Tabs are declared
 *    by `id` here and each renders `<slot :name="id">`, so a consumer never
 *    touches visibility or ARIA at all.
 * 3. TABS ARE IDENTIFIED BY STRING id, not array index. `songs` says what it
 *    selects where legacy's `1` said nothing, which matters as soon as a
 *    selection is passed in, stored, or read in a debugger.
 *
 * Which tab is open is `selectedTab`, and it is a MODEL rather than plain state:
 * bind it with `v-model:selected-tab` to own the selection from the page, or pass
 * it once (or not at all) and the strip keeps the selection itself. An absent or
 * unrecognised value resolves to the first tab, so the common case configures
 * nothing and a stale id degrades to a working strip instead of a blank panel.
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";

/** One tab: its id, its visible label, and optionally an icon and a count. */
export interface TabDefinition {
    /**
     * Stable identifier — it names the slot that fills the panel and is the value
     * `selectedTab` carries, so it should read as the thing the tab shows.
     */
    id: string;
    /** The visible, already-translated label. */
    label: string;
    /** Optional sprite icon rendered before the label. */
    icon?: string;
    /** Optional count shown after the label (e.g. how many rows the panel holds). */
    count?: number | null;
}

const props = withDefaults(
    defineProps<{
        /**
         * Unique name for this strip, used to build the tab / panel DOM ids. Two strips on
         * one page need different names or their aria-controls would cross-wire.
         */
        name: string;
        /** The tabs, in display order. Each needs a matching named slot. */
        tabs: TabDefinition[];
        /**
         * Accessible name for the tablist. Falls back to a generic label, but a consumer
         * should say what the tabs switch between — a page with one tablist is fine, a
         * screen reader user meeting two unnamed ones is not.
         */
        label?: string;
    }>(),
    { label: undefined }
);

/**
 * The open tab's id. Optional in both directions: unbound it is ordinary internal state,
 * and bound with `v-model:selected-tab` it hands the page control of the selection.
 */
const selectedTab = defineModel<string | undefined>("selectedTab", { default: undefined });

const { t } = useI18n();

/**
 * The tab actually shown: `selectedTab` when it names one of ours, else the first tab.
 *
 * Resolved through a computed rather than copied into state, which is what makes the strip
 * self-healing — an unset value, a typo, or a tab that disappeared when the data changed
 * all fall back to a real tab on their own, with no watcher to keep in step.
 */
const activeId = computed(() => {
    if (selectedTab.value && props.tabs.some(tab => tab.id === selectedTab.value)) return selectedTab.value;
    return props.tabs[0]?.id ?? "";
});

/** DOM ids, derived from `name` so a tab and its panel can point at each other. */
const tabDomId = (id: string): string => `tab-${props.name}-${id}`;
const panelDomId = (id: string): string => `tabpanel-${props.name}-${id}`;

/** Select a tab. A no-op re-click writes nothing, so a bound parent sees no spurious update. */
const activate = (id: string): void => {
    if (id === activeId.value) return;
    selectedTab.value = id;
};

const tablist = ref<HTMLElement | null>(null);

/**
 * Move focus to the tab at `index`, wrapping around either end, and select it.
 *
 * Selection follows focus, which is the APG recommendation for tabs whose panels are
 * already in the DOM (as both of ours are — the server sends them together): arrowing
 * along the strip shows each panel immediately, with no second key to confirm.
 *
 * The buttons are read out of the DOM rather than collected into a template-ref array,
 * so their order is the rendered order by construction and cannot drift from `tabs`.
 */
const focusTabAt = (index: number): void => {
    const count = props.tabs.length;
    if (count === 0) return;
    const wrapped = (index + count) % count;
    const tab = props.tabs[wrapped];
    if (!tab) return;
    activate(tab.id);
    tablist.value?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[wrapped]?.focus();
};

/**
 * Keyboard navigation along the strip: arrows step, Home / End jump to the ends.
 *
 * `preventDefault` runs only for keys actually handled, so anything else keeps its native
 * behaviour — and it IS needed for the ones that are: Home / End would otherwise scroll
 * the page away from the tabs the reader is using.
 */
const onKeydown = (event: KeyboardEvent, index: number): void => {
    switch (event.key) {
        case "ArrowRight":
            focusTabAt(index + 1);
            break;
        case "ArrowLeft":
            focusTabAt(index - 1);
            break;
        case "Home":
            focusTabAt(0);
            break;
        case "End":
            focusTabAt(props.tabs.length - 1);
            break;
        default:
            return;
    }
    event.preventDefault();
};
</script>

<template>
    <div class="tabbed-navigation">
        <div
            ref="tablist"
            class="tabbed-navigation__tabs"
            role="tablist"
            :aria-label="label ?? t('components.tabs.label')"
        >
            <!-- Roving tabindex: only the selected tab is tabbable, so Tab reaches the
                 strip once and then moves on into the panel, while the arrow keys move
                 between tabs. That is what the pattern expects, and it is why a strip of
                 tabs does not cost a keyboard user one Tab stop per tab. -->
            <button
                v-for="(tab, index) in tabs"
                :id="tabDomId(tab.id)"
                :key="tab.id"
                type="button"
                role="tab"
                class="tabbed-navigation__tab"
                :class="{ 'tabbed-navigation__tab--active': tab.id === activeId }"
                :aria-selected="tab.id === activeId"
                :aria-controls="panelDomId(tab.id)"
                :tabindex="tab.id === activeId ? 0 : -1"
                @click="activate(tab.id)"
                @keydown="onKeydown($event, index)"
            >
                <icon v-if="tab.icon" :name="tab.icon" :size="1" />
                <span>{{ tab.label }}</span>
                <!-- aria-hidden: the count is already part of the tab's accessible name
                     through the label text, and a bare number read after it ("Songs 406")
                     is noise rather than information. -->
                <span
                    v-if="tab.count !== null && tab.count !== undefined"
                    class="tabbed-navigation__count"
                    aria-hidden="true"
                    >{{ tab.count }}</span
                >
            </button>
        </div>

        <!-- Every panel stays MOUNTED and is hidden with v-show, not destroyed with v-if:
             the panels arrive together from the server, and a DataTable that unmounted on
             every tab switch would lose its scroll position and re-run its own setup for
             no gain. `display: none` also takes the hidden panel out of the accessibility
             tree and the tab order, which is exactly what an inactive panel needs. -->
        <div
            v-for="tab in tabs"
            v-show="tab.id === activeId"
            :id="panelDomId(tab.id)"
            :key="tab.id"
            class="tabbed-navigation__panel"
            role="tabpanel"
            :aria-labelledby="tabDomId(tab.id)"
        >
            <slot :name="tab.id" :active="tab.id === activeId" />
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* Per-breakpoint tab padding — a quarter of the token block-wise, half inline: the same
   thinning SiteMenuLinks applies to the same map, so a tab pill and a header link are the
   same shape as well as the same colour. Pulled into locals for the reason given there —
   the inline `map.get(...) * 0.25 map.get(...) * 0.5` form overruns 120 cols and reflows
   mid-`*`, tripping a stylelint rule that --fix cannot repair. */
$tab-pad: map.get(s.$c-tabbed-navigation, "tab-padding");
$tab-pad-base: map.get($tab-pad, "base") * 0.25 map.get($tab-pad, "base") * 0.5;
$tab-pad-portrait: map.get($tab-pad, "portrait") * 0.25 map.get($tab-pad, "portrait") * 0.5;
$tab-pad-landscape: map.get($tab-pad, "landscape") * 0.25 map.get($tab-pad, "landscape") * 0.5;
$tab-pad-desktop: map.get($tab-pad, "desktop") * 0.25 map.get($tab-pad, "desktop") * 0.5;

/* One framed panel, on the Card's metrics and surface: a tabbed block is the same kind of
   object as any other panel on a detail page, so it takes the same edge and the same
   `featured` rounding rather than inventing its own. */
.tabbed-navigation {
    display: flex;
    flex-direction: column;

    padding: map.get(s.$c-tabbed-navigation, "padding");
    border: map.get(s.$c-tabbed-navigation, "frame-border") solid map.get(c.$c-tabbed-navigation, "frame-border");
    gap: map.get(s.$c-tabbed-navigation, "gap");

    background-color: map.get(c.$c-tabbed-navigation, "frame-background");

    border-radius: map.get(s.$c-tabbed-navigation, "frame-radius");
}

.tabbed-navigation__tabs {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-tabbed-navigation, "tab-gap");
}

/* A pill, identical in shape and colour to a SiteMenuLinks link: the control that switches
   a page's content should look like the control that switches the site's sections. Both
   states carry a fill AND a border, so selecting a tab only swaps colours — nothing is
   added or removed, so the label never shifts. */
.tabbed-navigation__tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    box-sizing: border-box;

    min-width: map.get(s.$c-tabbed-navigation, "tab-min-width");
    border: map.get(s.$c-tabbed-navigation, "tab-border") solid map.get(c.$c-tabbed-navigation, "border");
    gap: map.get(s.$c-tabbed-navigation, "tab-inner-gap");

    background-color: map.get(c.$c-tabbed-navigation, "background");
    color: map.get(c.$c-tabbed-navigation, "surface");

    border-radius: map.get(s.$c-tabbed-navigation, "tab-radius");

    font: inherit;

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-tabbed-navigation linear,
            border-color ti.$c-tabbed-navigation linear,
            box-shadow ti.$c-tabbed-navigation linear,
            color ti.$c-tabbed-navigation linear;
    }

    /* Invert the resting pill (fill↔ink), the same flip a site menu link does. */
    &:hover {
        background-color: map.get(c.$c-tabbed-navigation, "background-hover");
        color: map.get(c.$c-tabbed-navigation, "surface-hover");
    }

    /* :focus-visible → keyboard only. OUTSIDE the pill rather than inset, since the pill is
       filled now and an inner ring would sit on its own fill; currentcolor so it reads on a
       resting and a selected tab alike. */
    &:focus-visible {
        outline: 2px solid currentcolor;
        outline-offset: 2px;
    }

    /* Selected: the site menu's "current section" highlight. Fill, ink AND border all move
       together, so the selected tab is never distinguished by hue alone — which matters for
       anyone who cannot separate the two, since `aria-selected` only reaches the reader who
       is being read to. */
    &--active {
        background-color: map.get(c.$c-tabbed-navigation, "active-background");
        color: map.get(c.$c-tabbed-navigation, "active-surface");
        border-color: map.get(c.$c-tabbed-navigation, "active-border");

        /* Thickens the edge WITHOUT resizing anything. An inset shadow with no offset and no
           blur is just a ring hugging the inside of the border, and in the border's own
           colour the two read as one heavier edge — `base` border plus the accent lands on
           `featured`. Widening `border-width` instead would grow the pill by the difference
           on every side, so labels would jump as selection moved along the strip;
           box-shadow is never part of layout, which is the whole reason it is the tool here.
           The ring is clipped to the padding box, so it follows the rounded corners free. */
        box-shadow: inset 0 0 0 map.get(s.$c-tabbed-navigation, "tab-border-accent")
            map.get(c.$c-tabbed-navigation, "active-border");

        font-weight: 600;

        /* Placed after the base `:hover` so it wins at equal specificity. */
        &:hover {
            background-color: map.get(c.$c-tabbed-navigation, "active-background-hover");
            color: map.get(c.$c-tabbed-navigation, "active-surface-hover");
        }
    }

    @include m.mqset("padding", $tab-pad-base, $tab-pad-portrait, $tab-pad-landscape, $tab-pad-desktop);
}

/* Sits after the label and reads as a quiet annotation to it, not as a second word. */
.tabbed-navigation__count {
    opacity: 0.7;

    font-variant-numeric: tabular-nums;
}
</style>
