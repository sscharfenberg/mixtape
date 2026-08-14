<script setup lang="ts">
/******************************************************************************
 * SearchField
 * The input both search mountings use: a leading glyph, the query, and a clear button.
 *
 * IT IS A COMBOBOX, not a text field with a list underneath. The ARIA pattern is what makes the
 * arrow keys mean anything to a screen reader: focus stays in the input while
 * `aria-activedescendant` points at the row the walk has reached, so the reader hears each row
 * announced without the caret ever leaving what they are typing. Moving real DOM focus to the
 * rows instead would announce just as well and then strand them — the next character typed would
 * go to a link.
 *
 * `type="text"` RATHER THAN `type="search"`, unlike DataTable's toolbar, and that is a
 * deliberate difference: a search input draws the browser's OWN clear button, which would sit
 * beside ours saying the same thing — and `role="combobox"` on a control the UA has already
 * decided is a search box is a fight not worth having. `autocomplete="off"` for the same reason
 * the pattern needs: the browser's own suggestion list would cover the app's.
 *
 * THE GLYPH IS A WELDED ADDON, the convention every field in this app follows
 * (styles/components/form/row/_addon.scss): an outer frame around the pair, the icon inside it,
 * and one border as the seam between icon and input — the addon keeping the leading corners while
 * the input keeps the trailing ones. It shares the field's fill and lights with it on focus, so
 * the two read as one control rather than as a glyph parked next to a box.
 *
 * THE WORKING INDICATOR SITS AT THE TRAILING END, and it is the app's own LoadingSpinner in its
 * `colored` pending tint — the same component and the same colour FormRow uses for an async
 * validation, so "working" looks the same wherever it appears. It went through two wrong shapes
 * first, both worth naming: the search glyph SPINNING (a magnifying glass has an
 * orientation, so rotating it reads as a broken icon rather than as progress), then a spinner
 * standing in for the glyph on the leading edge (which makes the thing that says what the field IS
 * flicker away while you use it). At the trailing end it is where every other field in this app
 * reports on itself, beside the clear button rather than in place of the label.
 *
 * The trailing end is a fixed-width slot whether or not anything is in it, so neither the spinner
 * arriving nor the clear button appearing moves the text under the reader's cursor.
 *
 * It shows from the keystroke rather than from the request, so the 200ms debounce reads as
 * "working" instead of as "nothing found".
 *
 * It does not own the keyboard. Every key it receives goes up to the host, which hands it to
 * useLibrarySearch — one keymap for both mountings, which is the whole point of the composable.
 *****************************************************************************/
import { useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

defineProps<{
    /** The query — `v-model` from the host. */
    modelValue: string;
    /** The results listbox this field controls (`aria-controls`), so the two are one widget. */
    listboxId: string;
    /**
     * The `id` of the row the arrows have landed on, or undefined for none.
     *
     * Valid despite the row not being a descendant of the input: for a combobox,
     * `aria-activedescendant` may name anything inside the element `aria-controls` points at.
     */
    activeOptionId?: string;
    /** Whether there is a result list on screen — the combobox's `aria-expanded`. */
    expanded: boolean;
    /** True while a question is being answered — what swaps the leading glyph for the spinner. */
    loading: boolean;
}>();

const emit = defineEmits<{
    /** The query changed — `v-model` on this component. */
    "update:modelValue": [value: string];
    /** Every key, for the host's one keymap — see the banner. */
    keydown: [event: KeyboardEvent];
}>();

const { t } = useI18n();

const input = useTemplateRef<HTMLInputElement>("input");

/**
 * Focus the input, for the two callers that must: the overlay when it opens (a field nobody can
 * type in is not a search), and the clear button, which would otherwise leave focus on a button
 * that has just removed its own reason to exist.
 */
function focus(): void {
    input.value?.focus();
}

/** Empty the field and put the caret back in it — the host's watcher does the rest. */
function clear(): void {
    emit("update:modelValue", "");
    focus();
}

defineExpose({ focus });
</script>

<template>
    <div class="search-field">
        <!-- Decorative: the field's own `aria-label` says what it is, and the results block
             announces what happened through its live region. A screen reader has no use for
             "picture of a magnifying glass". -->
        <span class="search-field__addon" aria-hidden="true">
            <icon name="search" :size="1" />
        </span>
        <input
            ref="input"
            :value="modelValue"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="expanded"
            :aria-controls="listboxId"
            :aria-activedescendant="activeOptionId"
            :aria-label="t('search.label')"
            :placeholder="t('search.placeholder')"
            autocomplete="off"
            spellcheck="false"
            enterkeyhint="search"
            class="search-field__input"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            @keydown="emit('keydown', $event)"
        />
        <!-- The trailing slot: what the field has to say about itself, and the way out of it. It
             holds its width whether or not either is showing — see the banner. -->
        <span class="search-field__actions">
            <loading-spinner v-if="loading" class="search-field__spinner colored" :size="1.5" />
            <!-- Only once there is something to clear: a button that does nothing is worse than
                 no button. -->
            <button
                v-if="modelValue !== ''"
                type="button"
                class="search-field__clear"
                :aria-label="t('search.clear')"
                @click="clear"
            >
                <icon name="close" :size="1" />
            </button>
        </span>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* THE WELDED PAIR — the shape every field in this app wears (form/row/_addon.scss): a frame
   around the whole control, the glyph inside it, and ONE border as the seam between glyph and
   input. So the frame lives on the two children rather than on this wrapper, which is only the
   row that holds them and the box the focus halo is drawn around.

   `position: relative` for the trailing slot, which is positioned over the input's own padding
   rather than placed after it: a third flex segment there would draw a second seam and read as an
   addon on the other end. */
.search-field {
    display: flex;
    position: relative;

    /* STRETCH, NOT CENTRE, and this is the whole reason the seam looks like a seam. The two
       segments have different natural heights — the addon is a 19px glyph, the input a 22.4px text
       line box — so centring them left the addon 35px against the input's 38.4px and the border
       stepped up and down where they met (the owner's report). Stretching hands the addon the
       input's height, exactly as FormRow's addon takes a textarea's; the glyph is then centred
       INSIDE it rather than the box being sized around the glyph. */
    align-items: stretch;

    box-sizing: border-box;

    border-radius: map.get(s.$c-search, "radius");

    @media (prefers-reduced-motion: no-preference) {
        transition: box-shadow ti.$c-search linear;
    }

    /* The app's neon focus glow, on the wrapper via `:focus-within` so it surrounds the pair
       rather than the input alone — the same two-layer treatment every focused control here
       gets. */
    &:focus-within {
        box-shadow:
            0 0 0 1px map.get(c.$c-search, "field", "glow"),
            0 0 8px map.get(c.$c-search, "field", "glow");
    }

    /* The addon keeps the LEADING corners and squares the trailing ones, which is the half of the
       convention that makes the seam a seam.

       Its WIDTH is fixed so the glyph sits dead centre; its HEIGHT is not set at all — the flex
       stretch above gives it the input's, which is what keeps the border unbroken across the seam.
       Setting one here is what broke it. */
    &__addon {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;

        box-sizing: content-box;
        width: map.get(s.$c-icon, "small");
        padding-inline: map.get(s.$c-search, "row", "padding");

        border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "field", "border");

        background-color: map.get(c.$c-search, "field", "background");
        color: map.get(c.$c-search, "muted");

        border-radius: map.get(s.$c-search, "radius") 0 0 map.get(s.$c-search, "radius");
    }

    /* …and the input keeps the trailing ones, with no border on the side it meets the addon: two
       bordered edges against each other draw a 4px line beside every 2px one, which is the exact
       fault the Select-in-a-FormRow seam was measured and fixed for.

       `min-width: 0` because a flex item's floor is its content, and a long query would otherwise
       push the control wider than the panel. */
    &__input {
        min-width: 0;
        flex: 1 1 auto;
        padding: map.get(s.$c-search, "row", "padding");

        // Room for the trailing slot, which floats over this padding — see `&__actions`.
        padding-inline-end: map.get(s.$c-search, "actions-reserve");
        border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "field", "border");
        border-inline-start: 0;

        background-color: map.get(c.$c-search, "field", "background");
        color: map.get(c.$c-search, "field", "surface");
        outline: 0;

        border-radius: 0 map.get(s.$c-search, "radius") map.get(s.$c-search, "radius") 0;

        font: inherit;

        &::placeholder {
            color: map.get(c.$c-search, "field", "placeholder");
        }
    }

    /* THE TRAILING SLOT, over the input's end padding. Its width is reserved by that padding
       whether or not anything is in it, so the spinner arriving and the clear button appearing
       both cost the text nothing — a field whose content shifts while you type in it is the one
       thing this arrangement exists to avoid. */

    /* PINNED TO BOTH BLOCK EDGES, which is the part that was missing: with only an inline offset
       an absolutely-positioned box takes its STATIC position for the other axis, and that position
       stopped being centred the moment the field switched from `align-items: center` to `stretch`
       (for the seam) — so the clear button drifted to the top of the field. Spanning the height and
       centring inside it does not depend on how the row aligns its items. */
    &__actions {
        display: flex;
        position: absolute;
        inset-inline-end: map.get(s.$c-search, "row", "padding");
        inset-block: 0;
        align-items: center;

        gap: map.get(s.$c-search, "gap");
    }

    /* POSITIONED, and not decoratively: LoadingSpinner draws itself entirely in two ABSOLUTE
       pseudo-elements, so without a positioned parent they would anchor to the nearest one — the
       panel — and the spinner would turn in its corner. FormRow's copy gets this for free by
       being absolutely placed itself; this one has to say it.

       `colored` (on the element) is the pending tint the token set already carries, so the working
       state is the app's blue here exactly as it is on a validating form field. The size is
       FormRow's, 1.5 — a step smaller reads as a stray comma, since this spinner draws an
       orbiting dot rather than a ring and there is not enough arc left below ~18px. */
    &__spinner {
        position: relative;
        flex-shrink: 0;
    }

    /* A REAL TAP TARGET. It was the glyph and nothing else — 19×19, which is under the 24px WCAG
       2.2 asks for and well under what a thumb wants. Stretching it to the field's height and
       padding it out inline makes it ~31×38 without moving the glyph or the text beside it: the
       room it needs was already reserved by the input's trailing padding (`actions-reserve`). */
    &__clear {
        display: flex;
        align-items: center;
        align-self: stretch;

        border: 0;

        background: none;
        color: map.get(c.$c-search, "muted");

        cursor: pointer;

        padding-inline: map.get(s.$c-search, "row", "padding");

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-search linear;
        }

        &:hover,
        &:focus-visible {
            color: map.get(c.$c-search, "field", "surface");
        }
    }
}
</style>
