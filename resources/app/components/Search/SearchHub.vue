<script setup lang="ts">
/******************************************************************************
 * SearchHub
 * A search field with its results floating over the card it sits on — the block a stats card
 * hosts, shared by the Music and Audiobooks cards, each scoped to its own area.
 *
 * IT IS THE AWKWARD HALF, which is why it is one component rather than one per card: the panel is
 * a native `[popover]` in the browser's TOP LAYER, anchored to the field with CSS anchor
 * positioning, shown and hidden by a watcher that has to guard on the element's own
 * `:popover-open` because `showPopover()` on one already showing THROWS. A second copy of that
 * is a second thing to get subtly wrong.
 *
 * WHY IT FLOATS AT ALL. Two properties of the Widget make an ordinary overlay impossible, and
 * both are there for good reasons of their own: `overflow: hidden` clips the title strip to the
 * card's rounded corners and would cut the panel off at the bottom border it is meant to hang
 * over, and `isolation: isolate` keeps the loader overlay's z-index inside the card and would
 * paint the panel UNDER the widgets that follow it in the DOM. A showing popover is in the top
 * layer: no ancestor clips it and no ancestor stacking context contains it.
 *
 * `manual`, NOT `auto`, so it does not light-dismiss: the panel shows exactly while there is a
 * query, and a click on a stat tile should not leave the field full and the answer gone. Escape
 * and the clear button both empty the field, which closes it.
 *
 * EACH MOUNTING IS ITS OWN SEARCH. `useLibrarySearch` is per-instance rather than a module
 * singleton, so the Music card and the Audiobooks card hold different questions — and `only`
 * decides which kinds each may answer with, which is what keeps an area's box inside its area.
 *****************************************************************************/
import { useTemplateRef, watch } from "vue";
import SearchField from "Components/Search/SearchField.vue";
import SearchResults from "Components/Search/SearchResults.vue";
import SearchScopeChips from "Components/Search/SearchScopeChips.vue";
import { useLibrarySearch } from "Composables/useLibrarySearch";
import type { SearchKind } from "Types/search";

const props = defineProps<{
    /**
     * Unique name for this hub — it names the CSS anchor and the chips' radio group, so two
     * hubs on one page would otherwise cross-wire.
     */
    name: string;
    /**
     * Which kinds this box may answer with. The header's overlay passes nothing and searches
     * everything; an area's card passes its own kinds so a book never turns up in a music
     * search and vice versa.
     */
    only: SearchKind[];
}>();

// No `onNavigate`: opening a result leaves this page anyway, and there is no panel to put away.
const { query, scope, groups, loading, failed, active, tooShort, listboxId, activeOptionId, onKeydown } =
    useLibrarySearch({ only: props.only });

/**
 * The anchor tying the results panel to the field.
 *
 * A dashed-ident, because that is what `anchor-name` takes, and bound into the scoped style
 * with `v-bind` — which only resolves inside an SFC, the same arrangement PopOver uses.
 */
const anchorName = `--${props.name}-search`;

/** The results panel, so its popover state can be driven from the query. */
const panel = useTemplateRef<HTMLElement>("panel");

/**
 * Show the panel exactly while there is a question, and put it away when there is not.
 *
 * Guarded on the element's own `:popover-open`, because `showPopover()` on one that is already
 * showing — and `hidePopover()` on one that is not — both THROW. `flush: "post"` because the
 * element is `v-if`'d on the same flag: it does not exist yet when the watcher would otherwise
 * run.
 */
watch(
    active,
    hasQuery => {
        const element = panel.value;
        if (!element) return;

        const showing = element.matches(":popover-open");
        if (hasQuery && !showing) element.showPopover();
        if (!hasQuery && showing) element.hidePopover();
    },
    { flush: "post" }
);
</script>

<template>
    <!-- The anchor the floating panel is positioned against — see the banner for why it
         floats at all. -->
    <div class="widget-stats__field">
        <search-field
            v-model="query"
            :listbox-id="listboxId"
            :active-option-id="activeOptionId"
            :expanded="groups.length > 0"
            :loading="loading"
            @keydown="onKeydown"
        />
    </div>

    <!-- `v-if` as well as the popover state, so an unsearched page carries no panel at all;
         the watcher above shows and hides it. -->
    <div v-if="active" ref="panel" class="widget-stats__results" popover="manual">
        <search-scope-chips
            v-model="scope"
            :name="`${name}-search-scope`"
            :kinds="only"
            class="widget-stats__chips"
        />
        <search-results
            :groups="groups"
            :listbox-id="listboxId"
            :active-option-id="activeOptionId"
            :loading="loading"
            :failed="failed"
            :too-short="tooShort"
        />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

/* The anchor. It draws nothing and exists so the floating panel has something to be positioned
   against — `anchor-name` has to sit on an element the panel can name, and the field is a child
   component whose root this cannot reach. */
.widget-stats__field {
    anchor-name: v-bind(anchorName);
}

/* THE FLOATING PANEL, positioned entirely by the anchor rather than by numbers of its own.
   `position-area: block-end span-inline-end` puts it below the field starting at the field's leading
   edge, and `width: anchor-size(width)` makes it exactly as wide as the box it answers for. Same
   family as this app's other anchored panels (styles/components/popover/_content.scss).

   `height: fit-content` WITH `max-height: stretch` IS THE PAIR THAT MATTERS, and it took three
   attempts. A fixed cap cannot know where the field sits: `min(26rem, 50dvh)` looked right on a
   900px window and ran 66px past the bottom of a 720px one — which is the height the E2E project
   runs at, so the test caught it. `stretch` resolves against the space the position-area actually
   has, so the ceiling is "as far as the window allows" wherever the field is, while `fit-content`
   keeps a two-row answer two rows tall. Measured at 1280×720: content 278px, bottom 704 against a
   720 viewport — the 16px is the block-end margin below, which `stretch` subtracts for us.

   `overflow: hidden` here with the scrolling on the LIST inside (SearchResults flexes and scrolls
   within it) is what keeps the rounded bottom corners while a long answer scrolls. */
.widget-stats__results {
    display: flex;
    position: fixed;
    z-index: z.$c-search;
    flex-direction: column;

    box-sizing: border-box;

    overflow: hidden;
    width: anchor-size(width);
    max-width: none;
    height: fit-content;
    max-height: stretch;
    padding: map.get(s.$c-search, "padding") 0;
    border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "border");

    // Block-end only, and load-bearing rather than decorative: `max-height: stretch` subtracts the
    // margins, so this is what keeps the panel off the bottom edge of the window.
    margin: 0 0 map.get(s.$c-search, "padding");
    gap: map.get(s.$c-search, "gap");

    background-color: map.get(c.$c-search, "background");
    color: map.get(c.$c-search, "surface");

    border-radius: map.get(s.$c-search, "radius");

    position-anchor: v-bind(anchorName);

    // Below the field, starting at its leading edge — see the block comment above.
    position-area: block-end span-inline-end;

    /* The list inside must scroll rather than the panel growing past its cap, which needs the
       flex-child floor removed — a flex item's default `min-height: auto` is its content. Its own
       `max-height` token is switched off here for the same reason: the panel is the cap now. */
    --search-results-height: none;

    :deep(.search-results) {
        min-height: 0;
        flex: 1 1 auto;
    }

    :deep(.search-results__list) {
        min-height: 0;
        flex: 1 1 auto;
    }
}

/* The chips keep the inset the panel gives up so the rows and strips can run edge to edge. */
.widget-stats__chips {
    padding-inline: map.get(s.$c-search, "padding");
}
</style>
