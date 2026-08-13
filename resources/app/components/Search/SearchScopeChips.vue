<script setup lang="ts">
/******************************************************************************
 * SearchScopeChips
 * The Music page's scope picker: alles / Alben / Künstler / Genres / Songs / Wiedergabelisten.
 *
 * IT IS WHY THE BROWSE WIDGETS GET NO SEARCH BOXES OF THEIR OWN. The first sketch put a field in
 * each of the four widgets, which is what v1 did — combined with the page's own field that is
 * five inputs, four of them a subset of the fifth, and each of the four would show a truncated
 * list with no honest way to say "and 72 more". One field plus these chips is the same capability
 * in one place (the owner's call, 2026-08-13). The widgets keep doing what they are for: latest,
 * random, most-played — browsing, not looking.
 *
 * RADIOS, not buttons, for the reason OptionBubbles records: a native radiogroup gives arrow-key
 * navigation between the options for free, moving focus AND selection the way a keyboard user
 * expects, and announces as one choice with six answers rather than as six unrelated controls.
 * The inputs stay in the DOM and are hidden by clipping rather than `display: none`, which would
 * take them out of the tab order with them.
 *
 * NOT OptionBubbles, though it is the same idea, and the reason is the sliding pill: that control
 * gives every option an equal share of the row so one element can travel between them, which
 * works for two or three glyphs and not for six labels of wildly different lengths
 * ("alles" against "Wiedergabelisten"). These wrap instead, at their own widths.
 *
 * NARROWING IS ONE QUESTION, NOT SIX. The chips send `?kinds=` to the same endpoint rather than
 * hitting a route of their own — two endpoints would be two ranking rules to keep in step.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import type { SearchScope } from "Types/search";

const props = defineProps<{
    /** The chosen scope — `v-model` from the host. */
    modelValue: SearchScope;
    /**
     * Radio `name`, and the prefix of each input's id, so it has to be unique on the page. Two
     * groups sharing a name would form ONE group, and choosing in the second would silently
     * clear the first — the same trap OptionBubbles documents.
     */
    name: string;
}>();

const emit = defineEmits<{
    /** The scope changed — `v-model` on this component. */
    "update:modelValue": [value: SearchScope];
}>();

const { t } = useI18n();

/**
 * The chips, left to right.
 *
 * "alles" first because it is the state the page opens in, then the kinds in the same order the
 * RESULTS use (artists → albums → playlists → songs → genres, App\Enums\SearchKind) — a picker
 * whose order disagreed with the list it filters would make the reader look twice for both.
 */
const scopes: SearchScope[] = ["all", "artist", "album", "playlist", "song", "genre"];

/** A chip's label: the kinds reuse the group headings, so a chip and its group read the same. */
function labelFor(scope: SearchScope): string {
    return scope === "all" ? t("search.scope.all") : t(`search.kind.${scope}`);
}

/** Input id — `name` is already unique per group, so this is too. */
function optionId(scope: SearchScope): string {
    return `${props.name}-${scope}`;
}
</script>

<template>
    <div class="search-scopes" role="radiogroup" :aria-label="t('search.scope.label')">
        <template v-for="scope in scopes" :key="scope">
            <input
                :id="optionId(scope)"
                type="radio"
                :name="name"
                :value="scope"
                :checked="scope === modelValue"
                @change="emit('update:modelValue', scope)"
            />
            <!-- Immediately after its input, with nothing in between: the checked and focus
                 styles are adjacent-sibling selectors, so an element in that gap would silently
                 unstyle the whole control. -->
            <label :for="optionId(scope)" class="search-scopes__chip">{{ labelFor(scope) }}</label>
        </template>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.search-scopes {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-search, "chip", "gap");

    /* Clipped rather than `display: none`: the inputs are what carry focus, arrow-key
       navigation and the group semantics, and none of that survives being removed from the
       layout. The standard visually-hidden recipe. */
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

    &__chip {
        padding: map.get(s.$c-search, "chip", "padding");

        background-color: map.get(c.$c-search, "chip", "background");
        color: map.get(c.$c-search, "chip", "surface");

        border-radius: map.get(s.$c-search, "chip", "radius");

        font-size: map.get(s.$c-search, "chip", "size");
        white-space: nowrap;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-search linear,
                color ti.$c-search linear;
        }

        /* The ring goes on the LABEL, because the input it belongs to is clipped to a pixel —
           an outline there would be invisible. `:focus-visible` keeps it to keyboard use. */
        input:focus-visible + & {
            outline: 2px solid map.get(c.$c-search, "chip", "background-selected");
            outline-offset: 2px;
        }

        input:checked + & {
            background-color: map.get(c.$c-search, "chip", "background-selected");
            color: map.get(c.$c-search, "chip", "surface-selected");
        }
    }
}
</style>
