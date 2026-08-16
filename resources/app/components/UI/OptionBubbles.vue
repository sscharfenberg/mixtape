<script setup lang="ts">
/******************************************************************************
 * OptionBubbles
 * A small icon-only picker: one glyph per option, with a single pill sliding behind
 * whichever is chosen. Two or three options, no visible text — the shape the header's
 * light / dark / system switch established, generalised so the player's settings can
 * use it twice.
 *
 * RADIOS, not buttons, and that is the whole reason this is not a pair of toggles.
 * A native radiogroup gives arrow-key navigation between the options for free, moving
 * focus AND selection the way a keyboard user expects; buttons would need every one of
 * those keys re-implemented, and would announce as two unrelated controls rather than
 * one choice with two answers. The inputs stay in the DOM and are hidden by clipping
 * rather than `display: none`, which would take them out of the tab order with them.
 *
 * The pill is ONE element behind the row rather than a background on the chosen option,
 * so it can travel: a background swap cannot animate from one element to another, and
 * the movement is what makes the control read as one choice rather than two lamps. Its
 * width and offset come from `--count` / `--selected` because the count is a prop.
 *
 * Icon-only controls have to say what they are twice: `aria-label` on each input for
 * assistive tech, and a tooltip for everyone else — the same pairing WidgetModeToggle
 * uses, for the same reason (a glyph is not a name). Those two need not be the same words:
 * an option may carry a `hint` for the tooltip, since a hovering reader is asking "what
 * happens if I press this" while a screen reader is announcing "which option is this". And
 * the chosen option may say something different again (`selectedHint`) — offering an action
 * on the option already in force reads as though the click had not registered.
 *
 * The pattern started in ThemeSwitch, the header's colour-scheme picker, which now
 * consumes this and kept only the part that was ever about colour schemes (the meta tag
 * and the persistence) — 169 lines down to 69, with its three token partials deleted.
 * That migration is what fixed the id handling below: a value may contain whitespace
 * ("light dark"), an id may not.
 *
 * Two callers, three shapes between them: two options in the player's settings, three
 * here. Which is why the count is a prop rather than a stylesheet full of `nth-of-type`
 * rules.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

/** Everything an option carries regardless of whether it draws as a glyph or as text. */
type BubbleOptionBase = {
    /** The value reported when this option is chosen. */
    value: string;
    /** Human name. The option's ACCESSIBLE name, and the tooltip unless `hint` says otherwise. */
    label: string;
    /**
     * Tooltip text while this option is NOT the chosen one, when naming it is not enough.
     *
     * Separate from `label` because the two answer different questions. A radio's accessible
     * name should be the thing it is ("Dark"), which is how assistive tech announces it
     * — "Dark, radio button, 1 of 3" — whereas a tooltip is read by someone hovering a glyph
     * and wondering what pressing it does, so it can afford a verb and a parenthetical
     * ("Switch to system mode (the OS decides…)").
     */
    hint?: string;
    /**
     * Tooltip text while this option IS the chosen one.
     *
     * Because an action is the wrong thing to offer on the option already in force: pressing
     * it changes nothing, so "switch to system mode" reads as though the switch had not
     * registered. Selected, the tooltip states what is in force instead ("System mode: the OS
     * decides…"), which is also the more useful sentence — it is the only moment the reader is
     * asking "what am I currently on?" rather than "what would this do?".
     */
    selectedHint?: string;
};

/**
 * One selectable option — drawn as EITHER a sprite glyph or a short piece of text, never
 * both and never neither, which is what the union enforces.
 *
 * Text was added for the player's speed row ("1×", "2×", "3×"), where a glyph would be a
 * picture of a number: the sprite has nothing that means "three times as fast", and inventing
 * one would be less legible than the two characters it replaced. Keep it SHORT — every option
 * is exactly one nth of the row (see `flex: 1 1 0` below, which is what makes the travelling
 * pill land true), so the longest string sets the width of ALL of them.
 */
export type BubbleOption = BubbleOptionBase &
    (
        | {
              /** Icon name from the sprite — the option's only visible content. */
              icon: string;
              text?: never;
          }
        | {
              icon?: never;
              /** Short text drawn in place of a glyph, for a choice no picture says better. */
              text: string;
          }
    );

const props = defineProps<{
    /** The currently selected value. Anything not among the options leaves the pill on the first. */
    modelValue: string;
    /** The options, left to right. */
    options: BubbleOption[];
    /**
     * Radio `name`, and the prefix of each input's id — so it has to be unique on the
     * page. Two groups sharing a name would form ONE group, and choosing in the second
     * would silently clear the first.
     */
    name: string;
    /** Accessible name for the group as a whole (its `aria-label`). */
    label: string;
    /**
     * Icon size step, passed through to `Icon`. Defaults to Icon's OWN default rather than
     * a step of this component's choosing, so adopting the control never silently resizes a
     * caller's glyphs — which is exactly what happened to the colour-scheme switch when it
     * migrated onto a hardcoded step 1 and its icons shrank from 24px to 19px.
     */
    size?: number;
}>();

const emit = defineEmits<{
    /** The chosen value changed — `v-model` on this component. */
    "update:modelValue": [value: string];
}>();

/**
 * Where the pill sits, as an option index.
 *
 * Falls back to 0 rather than -1 for an unknown value: the pill has to be somewhere,
 * and parking it off the left edge looks like a rendering fault rather than a state.
 */
const selectedIndex = computed<number>(() => {
    const index = props.options.findIndex(option => option.value === props.modelValue);

    return index === -1 ? 0 : index;
});

/**
 * What the tooltip says for one option, which depends on whether it is the chosen one.
 *
 * The chosen option describes the state in force; every other one describes the action it
 * would perform. Both fall back to the plain label, so a caller that has nothing extra to say
 * — the player's settings — keeps naming its glyphs and nothing more.
 */
function tooltipFor(option: BubbleOption): string {
    if (option.value === props.modelValue) return option.selectedHint ?? option.hint ?? option.label;

    return option.hint ?? option.label;
}

/**
 * Whether an option has anything to say beyond its own name.
 *
 * Which is the same question as "does the tooltip add information", so it is asked of the
 * tooltip rather than of the fields: an option whose tip is just its label would describe
 * itself twice to a screen reader, once as the name and once as the description.
 */
function hasDescription(option: BubbleOption): boolean {
    return tooltipFor(option) !== option.label;
}

/**
 * Id of an option's screen-reader description.
 *
 * The description exists because a hint alone is MOUSE-ONLY: hovering the system glyph
 * explains that the OS decides, while assistive tech hears "System" and has to guess — the
 * exact ambiguity the hint is written to remove. `aria-describedby` onto an `.sr-only` span
 * (the same helper DataTableHead uses for its sort announcements) gives both audiences the
 * same sentence, and because the span renders `tooltipFor`, it follows the selection the way
 * the tooltip does.
 */
function descriptionId(value: string): string {
    return `${optionId(value)}-description`;
}

/**
 * The input id for an option — `name` is already unique per group, so this is too.
 *
 * Whitespace is collapsed because it is legal in a VALUE and not in an id, and a
 * `label[for]` would never match it. The colour-scheme picker is exactly that case: its
 * third option is `"light dark"`, the CSS value meaning "follow the OS". Handled here
 * rather than asking every caller to pre-slug values it has no other reason to touch.
 */
function optionId(value: string): string {
    return `${props.name}-${value.replace(/\s+/gu, "-")}`;
}
</script>

<template>
    <div
        class="option-bubbles"
        role="radiogroup"
        :aria-label="label"
        :style="{ '--count': options.length, '--selected': selectedIndex }"
    >
        <span class="option-bubbles__pill" aria-hidden="true" />
        <template v-for="option in options" :key="option.value">
            <input
                :id="optionId(option.value)"
                type="radio"
                :name="name"
                :value="option.value"
                :checked="option.value === modelValue"
                :aria-label="option.label"
                :aria-describedby="hasDescription(option) ? descriptionId(option.value) : undefined"
                @change="emit('update:modelValue', option.value)"
            />
            <label
                v-tooltip="tooltipFor(option)"
                :for="optionId(option.value)"
                class="option-bubbles__item"
            >
                <icon v-if="option.icon" :name="option.icon" :size="size ?? 2" />
                <span v-else class="option-bubbles__text">{{ option.text }}</span>
            </label>
            <!-- AFTER the label, never between it and its input: the checked and focus styles
                 are adjacent-sibling selectors (`input:checked + .option-bubbles__item`), so an
                 element in that gap would silently unstyle the whole control. Out of flow via
                 `.sr-only`, so the flex row does not see it either. -->
            <span v-if="hasDescription(option)" :id="descriptionId(option.value)" class="sr-only">
                {{ tooltipFor(option) }}
            </span>
        </template>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* A GRID, not a flex row, and the reason is the pill. It is drawn as an exact
   `100% / var(--count)` slice at `--selected` slices along, so it only ever sits over the
   option it marks if the options are genuinely equal — and flex cannot promise that. Flex
   distributes FREE SPACE equally, never widths: with `flex-grow: 1` each item is its content
   plus a share of the remainder, and even `flex: 1 1 0` is clamped back up by each item's
   automatic minimum size, after which the container is sized as the plain sum. Measured on
   the sleep row's "Aus · 15 · 30 · 60": 42.81px against 35.2px, which put the first option
   2.9px off-centre inside its own pill and the last one true — the cumulative signature of
   this rather than of an alignment bug.
   `repeat(var(--count), …)` is the same number the pill divides by, so the two cannot drift.
   `minmax(0, 1fr)` rather than a bare `1fr` for the reason this project keeps meeting: `1fr`
   is `minmax(auto, 1fr)`, and that floor is min-content, which is exactly the clamp that
   broke the flex version.
   EVERYTHING ELSE IN HERE IS OUT OF FLOW — the pill, the clipped inputs and the `.sr-only`
   descriptions are all absolutely positioned — so the labels are the only grid items. Anything
   added here that is not positioned becomes a column and shifts the pill off every option. */
.option-bubbles {
    display: grid;
    position: relative;
    grid-template-columns: repeat(var(--count), minmax(0, 1fr));

    /* The travelling pill. Behind the row (`z-index` "background") rather than inside the
       chosen option, so one element can move between them — see the component banner. Its
       geometry is entirely `--count` / `--selected`: no per-count CSS to keep in step with
       however many options a caller passes. */
    &__pill {
        display: block;
        position: absolute;
        top: 0;
        left: calc(var(--selected) * 100% / var(--count));
        z-index: z.$c-option-bubbles;

        width: calc(100% / var(--count));
        height: 100%;

        background-color: map.get(c.$c-option-bubbles, "background-selected");
        border-radius: map.get(s.$c-option-bubbles, "radius");

        @media (prefers-reduced-motion: no-preference) {
            transition: left ti.$c-option-bubbles linear;
        }
    }

    /* Clipped rather than `display: none`: the inputs are what carry focus, arrow-key
       navigation and the group semantics, and none of that survives being removed from
       the layout. This is the standard visually-hidden recipe. */
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

    /* One grid column each (see the container above), so an option is always exactly the slice
       the pill draws for it. Still a flex box ITSELF, to centre its glyph or its text. */
    &__item {
        display: flex;
        align-items: center;
        justify-content: center;

        padding: map.get(s.$c-option-bubbles, "padding");

        color: map.get(c.$c-option-bubbles, "surface");

        line-height: 1;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-option-bubbles linear;
        }

        /* A text option stands in for a glyph, so it is sized and spaced like one rather
           than like body copy: `1em` matches the icon step this control uses at `size: 1`,
           and tabular figures keep "1×" and "3×" the same width — without them the pill,
           whose geometry assumes equal options, sits a fraction off centre on some of them.
           `nowrap` because these are two characters that must never become two lines. */
        .option-bubbles__text {
            font-size: 1em;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        /* The ring goes on the LABEL, because the input it belongs to is clipped to a
           pixel — an outline there would be invisible. `:focus-visible` keeps it to
           keyboard use. */
        input:focus-visible + & {
            outline: 2px solid map.get(c.$c-option-bubbles, "surface-selected");
            outline-offset: -2px;
        }

        input:checked + & {
            color: map.get(c.$c-option-bubbles, "surface-selected");
        }
    }
}
</style>
