<script setup lang="ts">
/******************************************************************************
 * SearchResults
 * The result surface — ONE component, mounted twice: in the header's overlay and in the Music
 * page's "Deine Musik" widget. The two hosts differ in where they sit and whether chips are
 * shown; what a result LOOKS like and how the keyboard walks it is written once, here.
 *
 * GROUPS IN A FIXED ORDER, NEVER SCORED AGAINST EACH OTHER (docs/search.md → Ranking). The
 * server sends them artists → albums → playlists → songs → genres and drops the empty ones, so
 * this component neither sorts nor filters: for `"karma police"` the first three groups have
 * nothing, songs arrives first, and the answer has floated to the top without anything having
 * been compared to anything else. A component that re-ordered would be the second opinion the
 * design exists to avoid.
 *
 * FIVE STATES, AND THE ORDER THEY ARE TESTED IN IS THE DESIGN. Too short comes before everything
 * — a reader who has typed two letters has not searched yet, and telling them "nothing found"
 * would be a lie about their library. Then a failure, then rows if there are any, then the
 * spinner, and only then "nothing found": the spinner outranks the empty state so a slow answer
 * never flashes "nothing" on its way in, which is the single most common way a typeahead reads as
 * broken.
 *
 * EVERY ROW IS A REAL LINK, and the `role="option"` on it is a second hat rather than a
 * replacement — the browser still gives it ⌘-click, a context menu and the status bar, which a
 * `<div>` with a click handler would not. The keyboard walk is a convenience laid over that, not
 * the only way in.
 *
 * THE HAND-OFF IS A ROW TOO. "Alle 77 in Songs anzeigen" is the last option in its group, so the
 * arrows reach it: it is the answer for the reader whose match is the seventy-eighth song, and it
 * is where the WIDE search still lives — that listing matches artist, album and genre as well,
 * sorted and paginated. Playlists never have one, their listing being a hand-ordered list with no
 * search of its own.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { SEARCH_MIN_LENGTH, searchOptionKey, searchSeeAllKey } from "Composables/useLibrarySearch";
import type { SearchGroup, SearchKind, SearchRow } from "Types/search";
import { formatDecimals } from "Utils/formatting";

const props = defineProps<{
    /** The groups to draw, in the server's order. */
    groups: SearchGroup[];
    /** The listbox's `id` — the field's `aria-controls` points at it. */
    listboxId: string;
    /** The `id` of the row the arrows have landed on, or undefined for none. */
    activeOptionId?: string;
    /** True while a question is being answered, including through the client's debounce. */
    loading: boolean;
    /** True when the last request could not be answered at all. */
    failed: boolean;
    /** True while the query is too short to be answered — the state that must not say "nothing found". */
    tooShort: boolean;
}>();

const emit = defineEmits<{
    /** A result was opened. The overlay closes itself on this; the Music page ignores it. */
    navigate: [];
}>();

const { t, locale } = useI18n();

/** Whether there is anything to draw at all — what decides between the list and a message. */
const hasRows = computed(() => props.groups.length > 0);

/**
 * The `id` one row's option carries.
 *
 * Built from the same `searchOptionKey` the composable's walk uses, so the two cannot disagree
 * about which element `aria-activedescendant` names — and prefixed with the listbox's own id,
 * because both mountings can be on the page at once and two elements sharing an id is a bug only
 * a screen reader would ever show you.
 */
function optionId(kind: SearchKind, id: string): string {
    return `${props.listboxId}-${searchOptionKey(kind, id)}`;
}

/** The `id` of a group's hand-off, which is a walkable option like any row. */
function seeAllId(kind: SearchKind): string {
    return `${props.listboxId}-${searchSeeAllKey(kind)}`;
}

/**
 * A row's second line: a name to print, or a number to pluralise for this kind.
 *
 * Both come off the server raw — the count is a number rather than the phrase "12 Alben",
 * because that phrase is German and a reader may be on the English catalog. Null where a kind
 * carries neither, which is a real case: a song whose file credits no artist.
 */
function metaFor(kind: SearchKind, row: SearchRow): string | null {
    if (row.text !== null) return row.text;
    if (row.count === null) return null;

    return t(`search.meta.${kind}`, row.count);
}

/** A group's accessible name — its kind and how many there really are. */
function groupLabel(group: SearchGroup): string {
    return `${t(`search.kind.${group.kind}`)}: ${formatDecimals(group.total, locale.value)}`;
}

/** "Alle 77 in Songs anzeigen" — the total, not the five on screen. */
function seeAllLabel(group: SearchGroup): string {
    return t("search.seeAll", {
        count: formatDecimals(group.total, locale.value),
        kind: t(`search.kind.${group.kind}`)
    });
}

/**
 * What a screen reader is told once an answer lands.
 *
 * A live region rather than nothing, because the rows appear without anything having been
 * pressed: focus stays in the field, so a reader who cannot see the panel gets no announcement at
 * all otherwise. It counts GROUPS rather than rows deliberately — "5 Künstler, 5 Songs" read out
 * per keystroke is noise, while "3 Gruppen" plus the arrow keys is an invitation to look.
 */
const announcement = computed<string>(() => {
    if (props.tooShort || props.loading) return "";
    if (props.failed) return t("search.failed");

    return hasRows.value ? t("search.found", props.groups.length) : t("search.empty");
});

/**
 * Keep the walked row on screen.
 *
 * The result area scrolls (five groups of five is taller than a phone), and the arrows move a
 * flag rather than DOM focus — so the browser does nothing about visibility on its own, which is
 * the price of keeping the caret in the field. `block: "nearest"` scrolls the minimum needed
 * instead of centring, so walking down a group does not pull the whole list about.
 *
 * `getElementById` rather than a template ref: the ids are already unique per mounting, and a ref
 * for every row in a list that repaints per keystroke is a lot of bookkeeping for one call.
 */
watch(
    () => props.activeOptionId,
    id => {
        if (id === undefined) return;
        document.getElementById(id)?.scrollIntoView({ block: "nearest" });
    },
    { flush: "post" }
);
</script>

<template>
    <div class="search-results">
        <!-- The states, in the order the banner argues for. -->
        <p v-if="tooShort" class="search-results__note">{{ t("search.minLength", { count: SEARCH_MIN_LENGTH }) }}</p>
        <p v-else-if="failed" class="search-results__note search-results__note--failed">
            <icon name="warning" :size="1" />
            {{ t("search.failed") }}
        </p>
        <div
            v-else-if="hasRows"
            :id="listboxId"
            class="search-results__list"
            role="listbox"
            :aria-label="t('search.results')"
        >
            <div v-for="group in groups" :key="group.kind" class="search-results__group" role="group" :aria-label="groupLabel(group)">
                <!-- Hidden from assistive tech because the group's own `aria-label` already
                     carries both halves of it; read out, this would be the second time. -->
                <p class="search-results__heading" aria-hidden="true">
                    <span>{{ t(`search.kind.${group.kind}`) }}</span>
                    <span class="search-results__total">{{ formatDecimals(group.total, locale) }}</span>
                </p>

                <Link
                    v-for="row in group.rows"
                    :id="optionId(group.kind, row.id)"
                    :key="row.id"
                    :href="row.href"
                    role="option"
                    :aria-selected="activeOptionId === optionId(group.kind, row.id)"
                    class="search-results__row"
                    :class="{ 'search-results__row--active': activeOptionId === optionId(group.kind, row.id) }"
                    @click="emit('navigate')"
                >
                    <span class="search-results__name">{{ row.name }}</span>
                    <span v-if="metaFor(group.kind, row) !== null" class="search-results__meta">
                        {{ metaFor(group.kind, row) }}
                    </span>
                </Link>

                <Link
                    v-if="group.seeAll !== null"
                    :id="seeAllId(group.kind)"
                    :href="group.seeAll"
                    role="option"
                    :aria-selected="activeOptionId === seeAllId(group.kind)"
                    class="search-results__row search-results__row--all"
                    :class="{ 'search-results__row--active': activeOptionId === seeAllId(group.kind) }"
                    @click="emit('navigate')"
                >
                    <span class="search-results__name">{{ seeAllLabel(group) }}</span>
                </Link>
            </div>
        </div>
        <p v-else-if="loading" class="search-results__note">{{ t("search.searching") }}</p>
        <p v-else class="search-results__note">{{ t("search.empty") }}</p>

        <!-- Outside every branch above, so the region exists before it has anything to say —
             a live region added to the DOM at the same moment as its text is not announced. -->
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.search-results {
    display: flex;
    flex-direction: column;

    /* The scrolling area. A ceiling rather than a height, so three rows take three rows'
       worth — see the token's own note on why it is `dvh` and not `vh`.

       THE CEILING IS OVERRIDABLE, via `--search-results-height`, because the two mountings sit in
       different kinds of space: the overlay hangs over the page and may take most of the screen,
       while the Music page's copy lives in a widget inside a grid, where a block that grows by
       300px shoves the four browse widgets off the fold. A custom property rather than a prop and
       a modifier class, the same way `--datatable-sticky-offset` lets a layout tell a component
       about space the component cannot see. */
    &__list {
        display: flex;
        flex-direction: column;

        overflow-y: auto;

        max-height: var(--search-results-height, #{map.get(s.$c-search, "results-height")});

        gap: map.get(s.$c-search, "padding");
    }

    &__group {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$c-search, "group", "gap");
    }

    /* Kind on one side, count on the other. Quieter than the rows under it: it is a label for
       them, and a heading that competes with its own contents makes the list harder to scan. */
    &__heading {
        display: flex;
        align-items: baseline;
        justify-content: space-between;

        padding: 0 map.get(s.$c-search, "row", "padding");
        margin: 0;

        color: map.get(c.$c-search, "muted");

        font-size: map.get(s.$c-search, "group", "heading-size");
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    &__total {
        font-variant-numeric: tabular-nums;
        letter-spacing: 0;
    }

    /* A row: name over meta. `position: relative` so the active row's glow paints over its
       neighbours rather than under the next opaque row — the same reason DataTable's hovered
       `<tr>` is positioned. */
    &__row {
        display: flex;
        position: relative;
        flex-direction: column;

        padding: map.get(s.$c-search, "row", "padding");

        color: map.get(c.$c-search, "surface");

        border-radius: map.get(s.$c-search, "row", "radius");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-search linear,
                box-shadow ti.$c-search linear;
        }

        &:hover {
            background-color: map.get(c.$c-search, "row-hover");
        }

        /* Where the arrows have landed — the app's neon "this is the live one", the same
           two-layer fill-plus-glow the DataTable's hovered row and the queue's loaded row use.
           It is a class rather than `:focus`, because focus deliberately stays in the field
           (SearchField's banner says why). */
        &--active {
            background-color: map.get(c.$c-search, "row-active-background");
            box-shadow:
                0 0 0 1px map.get(c.$c-search, "row-active-glow"),
                0 0 8px map.get(c.$c-search, "row-active-glow");
        }

        /* The hand-off reads as an action rather than as a result: one line, quieter, and no
           second line to leave a gap where a row's meta would be. */
        &--all {
            color: map.get(c.$c-search, "muted");

            font-size: map.get(s.$c-search, "row", "meta-size");
        }
    }

    /* One line, ellipsised. A song title can be four clauses long, and a name that wrapped to
       three lines would push the rest of the group off the panel. */
    &__name {
        overflow: hidden;

        white-space: nowrap;

        text-overflow: ellipsis;
    }

    &__meta {
        overflow: hidden;

        color: map.get(c.$c-search, "muted");

        font-size: map.get(s.$c-search, "row", "meta-size");
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    &__note {
        padding: map.get(s.$c-search, "row", "padding");
        margin: 0;

        color: map.get(c.$c-search, "muted");

        &--failed {
            display: flex;
            align-items: center;

            gap: map.get(s.$c-search, "row", "gap");
        }
    }
}
</style>
