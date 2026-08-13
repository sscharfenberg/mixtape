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
 * THE SPINNER TAKES THE GLYPH'S PLACE, in a slot of a fixed size. A search box that GREW a second
 * indicator while it works would move the field's content sideways under the reader's cursor, so
 * the addon is sized once and holds either the magnifying glass or the app's own LoadingSpinner —
 * the same component and the same `colored` pending tint FormRow uses for an async validation, so
 * "working" looks the same wherever it appears. (It was the search glyph SPINNING at first, which
 * the owner rightly called weird: a magnifying glass has an orientation, and rotating it reads as
 * a broken icon rather than as progress.) It appears from the keystroke rather than from the
 * request, so the 200ms debounce reads as "working" instead of as "nothing found".
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
        <!-- One slot, two contents, one size — see the banner. Hidden from assistive tech in both
             states: the results block announces what happened through its own live region, and a
             screen reader has no use for "picture of a magnifying glass". -->
        <span class="search-field__addon" aria-hidden="true">
            <loading-spinner v-if="loading" class="search-field__spinner colored" :size="1.5" />
            <icon v-else name="search" :size="1" />
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
        <!-- Only once there is something to clear: a button that does nothing is worse than no
             button, and on a phone it costs the query two characters of width. -->
        <button
            v-if="modelValue !== ''"
            type="button"
            class="search-field__clear"
            :aria-label="t('search.clear')"
            @click="clear"
        >
            <icon name="close" :size="1" />
        </button>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* One row: glyph, input, clear. The wrapper carries the frame so the three read as one control
   — and so the focus ring can be drawn around all of it rather than around the input alone,
   which would leave the glyph outside the thing that has focus. */
.search-field {
    display: flex;
    align-items: center;

    box-sizing: border-box;
    padding: 0 map.get(s.$c-search, "gap");
    border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "field", "border");

    gap: map.get(s.$c-search, "gap");

    background-color: map.get(c.$c-search, "field", "background");
    color: map.get(c.$c-search, "field", "surface");

    border-radius: map.get(s.$c-search, "radius");

    @media (prefers-reduced-motion: no-preference) {
        transition: box-shadow ti.$c-search linear;
    }

    /* The app's neon focus glow, moved from the input to the frame with `:focus-within` — the
       same two-layer treatment every focused control here gets, so the search box does not
       announce focus in a way of its own. */
    &:focus-within {
        box-shadow:
            0 0 0 1px map.get(c.$c-search, "field", "glow"),
            0 0 8px map.get(c.$c-search, "field", "glow");
    }

    /* A FIXED SQUARE, the size of the glyph it usually holds, so swapping in the spinner moves
       nothing. `flex-shrink: 0` because a long query must eat into the input, never into this. */
    &__addon {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;

        width: map.get(s.$c-icon, "small");
        height: map.get(s.$c-icon, "small");

        color: map.get(c.$c-search, "muted");
    }

    /* POSITIONED, and not decoratively: LoadingSpinner draws itself entirely in two ABSOLUTE
       pseudo-elements, so without a positioned parent they would anchor to whatever ancestor
       happens to be positioned — the panel — and the spinner would appear in its corner. FormRow's
       copy gets this for free by being absolutely placed itself; this one has to say it.

       The size is FormRow's, 1.5 — 18px against the 19px glyph it replaces, so the two carry the
       same weight in the same slot. A step smaller was tried and read as a stray comma: this
       spinner draws an orbiting dot rather than a ring, and below ~18px there is not enough arc
       left to look like motion. The dot is a `box-shadow` painted 0.2em OUTSIDE the box, so it
       orbits a few pixels into the field's own padding, which is why the slot needs no extra room
       for it. */
    &__spinner {
        position: relative;
    }

    /* Frameless: the wrapper is the frame. `min-width: 0` because a flex item's floor is its
       content, and a long query would otherwise push the clear button off the end. */
    &__input {
        min-width: 0;
        flex: 1 1 auto;
        padding: map.get(s.$c-search, "row", "padding") 0;
        border: 0;

        background: none;
        color: inherit;
        outline: 0;

        font: inherit;

        &::placeholder {
            color: map.get(c.$c-search, "field", "placeholder");
        }
    }

    &__clear {
        display: flex;
        align-items: center;

        padding: 0;
        border: 0;

        background: none;
        color: map.get(c.$c-search, "muted");

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-search linear;
        }

        &:hover,
        &:focus-visible {
            color: map.get(c.$c-search, "surface");
        }
    }
}
</style>
