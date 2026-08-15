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
 * EACH GROUP OPENS WITH A FULL-BLEED STRIP — its kind's glyph, the kind, and the total as a pill —
 * because the first draft separated groups with nothing but small grey capitals and read, in the
 * owner's words, as a grey mess. The strip runs edge to edge rather than sitting inside the panel's
 * padding: an inset band reads as a block of content, a full-width one reads as a divider, and the
 * whole job here is dividing. Its palette is the widget title's, with one measured deviation for
 * dark mode — `c.$c-search` → `heading-background` carries the numbers.
 *
 * A ROW'S TWO FACTS ARE PIPS, the shape the music widgets' cards already use (WidgetList): an icon
 * and a value, never a written label, because five rows × two facts of "Alben: 12" is a wall of
 * repeated words in a list meant to be scanned. The label survives in the tooltip, which is also
 * what stops the glyph carrying the meaning alone. WHICH two facts, and which glyph stands for
 * each, is this component's decision — the server sends a bag of raw values and nothing else.
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
import LabelledLink from "Components/UI/LabelledLink.vue";
import { SEARCH_MIN_LENGTH, searchOptionKey, searchSeeAllKey } from "Composables/useLibrarySearch";
import type { SearchFactKey, SearchGroup, SearchKind, SearchRow } from "Types/search";
import { formatClock, formatDecimals } from "Utils/formatting";

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

/** How a fact is drawn: which glyph stands for it, and how its raw value becomes a string. */
type FactSpec = {
    /** Sprite icon — the pip's whole visible label. */
    icon: string;
    /** What the fact IS, spelled out for the tooltip so the glyph never carries the meaning alone. */
    label: string;
    /** Raw → display. Counts pick up the locale's separators; seconds become a clock. */
    format: (value: number | string) => string;
};

/** The glyph that stands for each kind, on its heading strip. */
const KIND_ICONS: Record<SearchKind, string> = {
    artist: "artist",
    album: "album",
    playlist: "playlist",
    song: "song",
    genre: "genre",
    audiobook: "audiobook"
};

/**
 * WHICH FACTS EACH KIND SHOWS, in the order they are drawn:
 *
 *   artist → albums, total runtime      album → artist, tracks
 *   song   → artist, runtime            genre → artists, songs
 *   playlist → tracks, total runtime     audiobook → chapters
 *
 * The order lives here rather than in the server's payload because it is a layout decision, and
 * because a JSON object's key order is a poor thing to depend on. A key the server did not send —
 * or sent as null — simply draws no pip.
 */
const KIND_FACTS: Record<SearchKind, SearchFactKey[]> = {
    artist: ["albums", "duration"],
    album: ["artist", "songs"],
    playlist: ["tracks", "duration"],
    song: ["artist", "duration"],
    genre: ["artists", "songs"],
    // A book says most about itself with its chapter count — a 33-chapter anthology against a
    // 673-chapter novel.
    audiobook: ["tracks"]
};

/**
 * Whether there is anything to draw at all — what decides between the list and a message.
 */
const hasRows = computed(() => props.groups.length > 0);

/**
 * How to draw one fact. Counts are locale-formatted, runtimes are clocked, a credit prints as it
 * stands — and the labels are the ones the music widgets already use for the same facts, so a
 * reader meeting "12 albums" here and on a card is told it the same way.
 */
function factSpec(key: SearchFactKey): FactSpec {
    const count = (value: number | string): string => formatDecimals(Number(value), locale.value);
    const clock = (value: number | string): string => formatClock(Number(value)) ?? "";

    switch (key) {
        case "albums":
            return { icon: "album", label: t("music.pips.albumCount"), format: count };
        case "artists":
            return { icon: "artist", label: t("music.pips.artistCount"), format: count };
        case "songs":
            return { icon: "song", label: t("music.pips.songCount"), format: count };
        case "tracks":
            return { icon: "track", label: t("playlists.facts.tracks"), format: count };
        case "duration":
            return { icon: "duration", label: t("music.pips.totalDuration"), format: clock };
        case "artist":
        default:
            return { icon: "artist", label: t("music.columns.artist"), format: String };
    }
}

/** One row's pips, in this kind's order, with the facts it does not have left out. */
function pipsFor(kind: SearchKind, row: SearchRow): Array<{ key: string; icon: string; label: string; value: string }> {
    return KIND_FACTS[kind].flatMap(key => {
        const value = row.facts[key];
        if (value === undefined || value === null || value === "") return [];

        const spec = factSpec(key);

        return [{ key, icon: spec.icon, label: spec.label, value: spec.format(value) }];
    });
}

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
                    <icon :name="KIND_ICONS[group.kind]" :size="1" />
                    <span class="search-results__kind">{{ t(`search.kind.${group.kind}`) }}</span>
                    <span class="search-results__count">{{ formatDecimals(group.total, locale) }}</span>
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
                    <!-- The tip sits on the whole pip, not on the icon: the value is part of what
                         it says, and anchoring to the glyph alone would leave the number beside it
                         unexplained. The same reasoning WidgetList records. -->
                    <span v-if="pipsFor(group.kind, row).length > 0" class="search-results__pips">
                        <span
                            v-for="pip in pipsFor(group.kind, row)"
                            :key="pip.key"
                            v-tooltip="`${pip.label}: ${pip.value}`"
                            class="search-results__pip"
                        >
                            <icon :name="pip.icon" :size="0" />
                            <!-- Its own element rather than a bare text node so it can be
                                 ellipsised: `text-overflow` needs a box to act on. -->
                            <span class="search-results__pip-value">{{ pip.value }}</span>
                        </span>
                    </span>
                </Link>

                <!-- A LabelledLink rather than a bare row: the way OUT of a
                     group is an action, so it wears the app's link treatment — underline and a
                     leading glyph — over a band of its own. Its attributes fall through to the
                     `<a>` LabelledLink renders, which is what keeps it a walkable option with the
                     same id the keyboard expects.

                     `prefetch` is left off. The rule is about what a link LEADS TO, and a listing
                     is safe to warm — but this one is a dropdown row a reader sweeps the pointer
                     across on the way to something else, and warming a paginated table per sweep
                     buys nothing. -->
                <labelled-link
                    v-if="group.seeAll !== null"
                    :id="seeAllId(group.kind)"
                    :href="group.seeAll"
                    :icon="KIND_ICONS[group.kind]"
                    role="option"
                    :aria-selected="activeOptionId === seeAllId(group.kind)"
                    class="search-results__row search-results__row--all"
                    :class="{ 'search-results__row--active': activeOptionId === seeAllId(group.kind) }"
                    @click="emit('navigate')"
                >
                    {{ seeAllLabel(group) }}
                </labelled-link>
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

    /* The scrolling area, and it is deliberately FULL WIDTH — its host gives it no inline padding
       (SearchOverlay's panel pads only the block axis), which is what puts the scrollbar at the
       panel's own inner edge instead of floating it inside the padding, a step in from the edge.
       That was the owner's report: a scrollbar sitting oddly to the left of the right padding.
       The inset the rows still need is theirs, not this container's.

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
    }

    /* NO GAP between the strip and its rows, and none between the rows. The
       strip is a header attached to the set beneath it, and the zebra stripes only read as a rhythm
       if they actually touch — air between them turns a banded list back into a stack of separate
       blocks, which is what the strips were introduced to stop. */
    &__group {
        display: flex;
        flex-direction: column;
    }

    /* THE DIVIDER, and it earns that word by running edge to edge: kind glyph, kind, count. The
       palette is the widget title's, with a measured dark-mode substitution — `c.$c-search` →
       `heading-background` carries the contrast figures and why the widget's own dark ink could
       not be borrowed.

       `position: sticky` is deliberately NOT set. A strip per group that stuck would stack up as
       the reader scrolled a five-group answer, and with `LIMIT 5` per kind there is never enough
       list under one heading for stickiness to pay for itself. */
    &__heading {
        display: flex;
        align-items: center;

        // Block: tight — it is a rule with words in it, not a block of content. Inline: the
        // PANEL's padding, so the label starts on the same vertical as the field above it and the
        // names below it, even though the strip's fill runs past both to the panel's edges.
        padding: map.get(s.$c-search, "group", "heading-padding") map.get(s.$c-search, "padding");
        margin: 0;
        gap: map.get(s.$c-search, "pip", "gap");

        background: map.get(c.$c-search, "heading-background");
        color: map.get(c.$c-search, "heading-surface");

        font-size: map.get(s.$c-search, "group", "heading-size");
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Takes the slack, so the count is pushed to the trailing edge whatever the kind is called. */
    &__kind {
        flex: 1 1 auto;
    }

    /* The count as a pill — Badge's geometry, the panel's own colours. It reads as a hole punched
       through the strip, which is also what makes its contrast the panel's own (the highest pair
       in the token set) rather than something that has to be measured against a gradient. */
    &__count {
        padding: map.get(s.$c-search, "count", "padding");

        background-color: map.get(c.$c-search, "count-background");
        color: map.get(c.$c-search, "count-surface");

        border-radius: map.get(s.$c-search, "count", "radius");

        font-size: map.get(s.$c-search, "count", "size");
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        letter-spacing: 0;
    }

    /* A row: name over pips. `position: relative` so the active row's glow paints over its
       neighbours rather than under the next opaque row — the same reason DataTable's hovered
       `<tr>` is positioned. The inline padding is the row's own, since the list around it has
       none; it is what lines the names up with the field above them. */

    /* ONE LINE WHERE THERE IS ROOM, TWO WHERE THERE IS NOT, and flex wrapping
       gives exactly that for free — because of the thing that is usually a trap. A wrapping flex
       line is broken by each item's MAX-CONTENT width, not by the width it could shrink to, so a
       name longer than the row leaves has the pips wrap to a second line rather than squeezing them;
       once alone on line one, the name then ellipsises as it always did. Same mechanism as the
       `<Headline>` bug in the memory of this repo, used deliberately this time.

       Which also means: NO `flex: 1 1` on the name. Letting it grow would fill the line and push the
       pips down every time, and letting it shrink would keep them up there ellipsising the title
       into nothing. Its natural width, plus `min-width: 0` so it can still ellipsise when it is the
       only thing on the line, is the whole arrangement. */
    &__row {
        display: flex;
        position: relative;
        align-items: center;

        /* TRAILING ON EITHER LINE — see `__pips`, which owns the placing with an auto margin.
           NOT `justify-content: space-between` here: that packs a line holding a single item to
           the main-START edge, so a pips line that wrapped would sit flush LEFT under the name.
           This row decides only the flow and the gaps. */
        flex-flow: row wrap;

        // Inline from the PANEL's padding rather than the row's own, for the reason the strip
        // above gives: the list has no padding of its own, so this is what puts every name on the
        // same vertical as the field.
        padding: map.get(s.$c-search, "row", "padding") map.get(s.$c-search, "padding");

        // Column: the least space allowed between a name and the pips sharing its line. Row: what
        // separates the two lines when they do not.
        gap: map.get(s.$c-search, "pip", "gap") map.get(s.$c-search, "gap");

        color: map.get(c.$c-search, "surface");

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-search linear,
                box-shadow ti.$c-search linear;
        }

        /* ZEBRA, and it counts `<a>` elements only — `nth-of-type` rather than `nth-child`,
           because the group's first child is the heading `<p>` and counting that would flip every
           stripe and leave the first row on the "even" wash. The hand-off is an `<a>` too and so
           takes a turn in the sequence, which is why its own band below has to win. */
        &:nth-of-type(odd) {
            background-color: map.get(c.$c-search, "row-odd");
        }

        &:nth-of-type(even) {
            background-color: map.get(c.$c-search, "row-even");
        }

        /* After the stripes, so it beats whichever one the sequence handed it at equal
           specificity — and before hover/active, which must beat this in turn. */
        &--all {
            background-color: map.get(c.$c-search, "see-all-background");
        }

        /* FOCUS TAKES THE HOVER WASH, because a row is reachable two ways and
           only one of them had any feedback. The arrow keys move a flag and leave the caret in the
           field (`--active` below), but TAB moves real focus through these links — and with nothing
           but the UA's ring to go on, a keyboard reader tabbing a five-group answer could not see
           where they were.

           `:focus-visible` rather than `:focus`: it is the same wash, and this way a row does not
           light up under a mouse click on its way to navigating away. */
        &:hover,
        &:focus-visible {
            background-color: map.get(c.$c-search, "row-hover");
        }

        /* Where the arrows have landed — the app's neon "this is the live one", the same
           two-layer fill-plus-glow the DataTable's hovered row and the queue's loaded row use.
           It is a class rather than `:focus`, because focus deliberately stays in the field
           (SearchField's banner says why).

           No `border-radius` on either state, unlike the first draft: the row spans the panel's
           full width now, so a rounded corner would cut the fill away from an edge it is meant to
           meet. */
        &--active {
            background-color: map.get(c.$c-search, "row-active-background");
            box-shadow:
                inset 0 0 0 1px map.get(c.$c-search, "row-active-glow"),
                inset 0 0 8px map.get(c.$c-search, "row-active-glow");
        }

        /* The hand-off reads as an action rather than as a result: one line, a size down, and no
           pips to leave a gap where a row's facts would be.

           NOTHING TO UNDO HERE, because the trailing edge is an auto margin on `__pips` and this
           row has no pips. Were the row using `space-between` instead, this would have to override
           it: LabelledLink's two children are a glyph and its label, and pushing those to opposite
           ends of the panel reads as two unrelated things rather than as one labelled link. The
           tighter gap is its own decision — 8px reads as two objects side by side, 4px as one. */
        &--all {
            align-items: center;
            flex-direction: row;

            gap: map.get(s.$c-search, "pip", "gap");

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

    /* AN AUTO MARGIN DOES THE PLACING, and it is the whole of the trailing-edge rule: auto margins
       are resolved BEFORE `justify-content` and absorb all the free space on their own flex LINE,
       so the pips sit flush right whether they share the name's line or have one to themselves.

       `justify-content: space-between` is the alternative and does NOT do the same job: it packs
       a line holding a single item to the main-START edge, so a pips line with the name above it
       lands flush left, lined up under the name. The requirement is the opposite — a fact sits at
       the trailing edge on every row, so the eye finds it in one column down a list of nine — and
       an auto margin is what holds on both the shared line and the wrapped one.

       `nowrap` keeps the pips ONE item as far as the wrapping row above is concerned, so they move
       down together instead of one at a time. */
    &__pips {
        display: flex;
        flex-wrap: nowrap;

        overflow: hidden;

        margin-inline-start: auto;
        gap: map.get(s.$c-search, "pip", "gap");
    }

    /* A fact: glyph plus value, on its own quiet wash. Opaque rather than translucent so the pair
       has exactly one contrast ratio to answer for whatever state the row is in — the token
       carries the measurement. */
    &__pip {
        display: inline-flex;
        align-items: center;

        min-width: 0;
        padding: map.get(s.$c-search, "pip", "padding");
        gap: 0.4ch;

        background-color: map.get(c.$c-search, "pip-background");
        color: map.get(c.$c-search, "pip-surface");

        border-radius: map.get(s.$c-search, "pip", "radius");

        /* NO font-size, deliberately: a pip is the name's size. It
           went 0.7rem → 0.8rem → inherited over one afternoon, and each step was the same
           correction — a fact nobody can read is not a fact. What separates a pip from the name it
           sits with is its wash and its glyph, which is plenty; shrinking the text as well was
           saying "less important" twice and costing legibility for it. */
        white-space: nowrap;

        /* The glyph is the pip's label, so it is the one part that must never give ground — flex
           distributes shrink in proportion to base width, and without this the icon squashes
           before the text it names does. */
        svg {
            flex-shrink: 0;
        }
    }

    &__pip-value {
        overflow: hidden;

        text-overflow: ellipsis;
    }

    &__note {
        padding: map.get(s.$c-search, "row", "padding") map.get(s.$c-search, "padding");
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
