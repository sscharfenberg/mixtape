/******************************************************************************
 * useLibrarySearch
 * The client half of the cross-kind search (docs/search.md): one query, one request per pause in
 * typing, one set of groups to draw, and one keyboard walk over them.
 *
 * ONE COMPOSABLE, MOUNTED TWICE. The header overlay and the Music page's block differ in where
 * they sit and whether chips are shown — nothing else — so both call this and both render the
 * same SearchResults. Two implementations would be two sets of keyboard handling, and the second
 * one would be the one that quietly stops matching the first.
 *
 * PER-INSTANCE STATE, NOT A MODULE SINGLETON, which makes it the opposite of this app's other
 * composables (useToast, usePlayerQueue, usePlayQueuePanel). Those hold one thing that exists
 * once; a search box holds what somebody is typing INTO IT, and the Music page and the overlay
 * are two boxes that can legitimately be showing different questions. Sharing the query would
 * mean opening the overlay silently re-ran whatever was left in the page's field, and the two
 * listboxes would fight over one active row.
 *
 * THREE THINGS IT DOES THAT ARE EASY TO GET WRONG, in the order they bite:
 *
 *   1. THE ABORT. Without it a slow answer for "bla" can land after "black" and paint stale
 *      rows, which reads as the search being WRONG rather than late. Every request carries an
 *      AbortController and a new one cancels the last — plus an identity check on the way back
 *      in, because an abort is a race and not a guarantee: a response already parsed cannot be
 *      un-received, so the query it was asked for is compared with the query on screen before
 *      anything is drawn.
 *   2. THE FLOOR. Three characters, matching the server's (App\Services\Search\LibrarySearch),
 *      and it is about RESULT QUALITY rather than speed — a two-character query was measured at
 *      5.0 ms and matches half the library. Below it nothing is requested and nothing is
 *      painted, but the block stays open to say why.
 *   3. THE DEBOUNCE. 200ms of quiet before asking, so a typed word is one request rather than
 *      five. A chip is NOT debounced: it is one deliberate press, and waiting a fifth of a
 *      second after a click reads as lag.
 *
 * IT NAVIGATES WITH `router.visit`, not with a page reload, and tells its host afterwards
 * (`onNavigate`) so the overlay can put itself away. The rows are real links as well, so the
 * mouse never needs any of this — the keyboard walk is a convenience on top, which is why Enter
 * on nothing is left alone rather than swallowed.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, getCurrentScope, onScopeDispose, ref, watch } from "vue";
import type { SearchGroup, SearchKind, SearchResponse, SearchScope, SearchTarget } from "Types/search";
import { debounce } from "Utils/debounce";

/**
 * The shortest query that is answered at all — the same three the server enforces.
 *
 * Duplicated on purpose rather than shared through a prop: the client's copy stops a pointless
 * request, the server's copy stops a hand-written URL, and neither is a substitute for the other.
 */
export const SEARCH_MIN_LENGTH = 3;

/** How long the reader has to stop typing before the question is asked. */
const DEBOUNCE_MS = 200;

/** The `id` suffix a group's "see all" target carries — a row id is a UUID, so it cannot collide. */
const SEE_ALL = "all";

/**
 * How many mountings have been created, so each gets DOM ids of its own.
 *
 * The overlay and the Music page block can both be on the page at once, and two listboxes
 * sharing option ids would give `aria-activedescendant` two elements to mean — which is a
 * duplicate-id bug that only shows up in a screen reader.
 */
let instances = 0;

/**
 * The key that identifies one walkable target within a mounting.
 *
 * Exported because it is computed in two places that must agree — here, building the walk, and
 * in SearchResults, stamping the ids the walk points at. One formula, one place.
 */
export const searchOptionKey = (kind: SearchKind, id: string): string => `${kind}-${id}`;

/** The key of a group's "see all" link. */
export const searchSeeAllKey = (kind: SearchKind): string => searchOptionKey(kind, SEE_ALL);

/** What {@link useLibrarySearch} hands its caller. */
export type UseLibrarySearchReturn = {
    /** What the reader has typed — `v-model` on the field. */
    query: Ref<string>;
    /** Which kinds may answer. Only the Music page shows a control for this. */
    scope: Ref<SearchScope>;
    /** The groups to draw, in the server's fixed order. Empty until an answer lands. */
    groups: Ref<SearchGroup[]>;
    /** True from the keystroke until the answer paints, so a spinner covers the debounce too. */
    loading: Ref<boolean>;
    /** True when the last request could not be answered — offline, a 429, an expired session. */
    failed: Ref<boolean>;
    /** True while there is a question at all — what the Music page swaps its stat tiles on. */
    active: ComputedRef<boolean>;
    /** True while the question is too short to answer, so the block can say so rather than sit blank. */
    tooShort: ComputedRef<boolean>;
    /** The `id` of the results listbox, unique to this mounting. */
    listboxId: string;
    /** The `id` of the row the arrows have landed on, or undefined when they have landed on none. */
    activeOptionId: ComputedRef<string | undefined>;
    /** Arrow keys walk the rows, Enter opens one, Escape clears. Bind on the field. */
    onKeydown: (event: KeyboardEvent) => void;
    /** A row was opened by the mouse — navigation is the link's own, this is the bookkeeping. */
    noteNavigation: () => void;
    /** Empty the field and forget the answer, dropping any request still in flight. */
    clear: () => void;
};

/**
 * Wire up one search box.
 *
 * @param options.onNavigate called after a result has been opened, so a host that is an overlay
 *                           can close itself. Nothing to do for the Music page, which stays put.
 */
export const useLibrarySearch = (
    options: { onNavigate?: () => void; only?: SearchKind[] } = {}
): UseLibrarySearchReturn => {
    const listboxId = `search-results-${++instances}`;

    const query = ref("");
    const scope = ref<SearchScope>("all");
    const groups = ref<SearchGroup[]>([]);
    const loading = ref(false);
    const failed = ref(false);

    /** Where the arrow keys have got to, or -1 for "nowhere yet". */
    const activeIndex = ref(-1);

    /** The request in flight, so the next one can cancel it. See the banner's point 1. */
    let inFlight: AbortController | null = null;

    const trimmed = computed(() => query.value.trim());
    const active = computed(() => trimmed.value !== "");
    const tooShort = computed(() => active.value && trimmed.value.length < SEARCH_MIN_LENGTH);

    /**
     * Everything the arrows can land on, flattened in the order it is drawn — each group's rows,
     * then that group's "see all" link where it has one.
     */
    const targets = computed<SearchTarget[]>(() =>
        groups.value.flatMap(group => [
            ...group.rows.map(row => ({ key: searchOptionKey(group.kind, row.id), href: row.href })),
            ...(group.seeAll === null ? [] : [{ key: searchSeeAllKey(group.kind), href: group.seeAll }])
        ])
    );

    const activeOptionId = computed(() => {
        const target = targets.value[activeIndex.value];

        return target === undefined ? undefined : `${listboxId}-${target.key}`;
    });

    /**
     * Where to ask.
     *
     * `kinds` is omitted entirely for an unconstrained "all" rather than sent empty, because
     * that is the endpoint's own way of saying it and one shape fewer for the request to
     * validate.
     *
     * `only` NARROWS WHAT "ALL" MEANS for this box, which is what keeps the areas apart (the
     * owner's call): the header searches the whole library, the Music page's field must not
     * answer with audiobooks, and the audiobook card must not answer with music. A chip
     * still narrows further within that — it is a subset of the box's own reading, never a
     * way out of it.
     */
    const urlFor = (asked: string, asScope: SearchScope): string => {
        const kinds = asScope === "all" ? (options.only ?? []) : [asScope];
        // Comma-separated, which is the form SearchRequest documents as the one a human might
        // type; it accepts `kinds[]=` too, but there is no reason for two shapes on the wire.
        const filter = kinds.length === 0 ? "" : `&kinds=${kinds.join(",")}`;

        return `/search?q=${encodeURIComponent(asked)}${filter}`;
    };

    /** Whether an answer is still the answer to the question on screen — the banner's point 1. */
    const stillCurrent = (asked: string, asScope: SearchScope): boolean =>
        asked === trimmed.value && asScope === scope.value;

    /** Nothing to show and a reason to say so. Shared by the two ways a request can fail. */
    const paintFailure = (): void => {
        groups.value = [];
        activeIndex.value = -1;
        failed.value = true;
        loading.value = false;
    };

    /**
     * Ask, and paint only if the answer is still wanted.
     *
     * A plain `fetch` rather than an Inertia visit for the reason the endpoint exists at all
     * (SearchController): the reader is not navigating. No CSRF token to send, unlike this app's
     * two other fetch callers — this is a GET.
     */
    const run = async (): Promise<void> => {
        const asked = trimmed.value;
        const asScope = scope.value;

        inFlight?.abort();
        const controller = new AbortController();
        inFlight = controller;

        try {
            const response = await fetch(urlFor(asked, asScope), {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                signal: controller.signal
            });

            if (!stillCurrent(asked, asScope)) return;

            if (!response.ok) {
                paintFailure();

                return;
            }

            const body = (await response.json()) as SearchResponse;
            if (!stillCurrent(asked, asScope)) return;

            groups.value = body.groups;
            activeIndex.value = -1;
            failed.value = false;
            loading.value = false;
        } catch {
            // An abort is not a failure — it is this request being superseded, and the one that
            // superseded it owns the spinner from here.
            if (controller.signal.aborted) return;
            if (!stillCurrent(asked, asScope)) return;

            paintFailure();
        } finally {
            if (inFlight === controller) inFlight = null;
        }
    };

    const ask = debounce(() => {
        void run();
    }, DEBOUNCE_MS);

    /** Drop the question: no request, no rows, no spinner. */
    const forget = (): void => {
        ask.cancel();
        inFlight?.abort();
        inFlight = null;
        groups.value = [];
        activeIndex.value = -1;
        loading.value = false;
        failed.value = false;
    };

    /**
     * A new question — from a keystroke or from a chip.
     *
     * `loading` goes up on the KEYSTROKE rather than when the request leaves, so the block says
     * "working" through the debounce as well: a fifth of a second of a blank panel looks like an
     * answer of "nothing".
     *
     * A chip skips the debounce, since a click is not a burst — see the banner's point 3.
     */
    watch([trimmed, scope], ([, nextScope], [, previousScope]) => {
        activeIndex.value = -1;

        if (!active.value || tooShort.value) {
            forget();

            return;
        }

        loading.value = true;

        if (nextScope !== previousScope) {
            ask.cancel();
            void run();

            return;
        }

        ask();
    });

    /**
     * Move the walk, wrapping at both ends.
     *
     * It wraps rather than stopping because the list is short and grouped: from the last genre
     * back to the first artist is one press, where a clamp would be a dozen presses back up
     * through rows the reader has already rejected. From "nowhere yet", down lands on the first
     * row and up on the last, which is the only reading of -1 that is not arbitrary.
     */
    const move = (delta: number): void => {
        const count = targets.value.length;
        if (count === 0) return;

        activeIndex.value =
            activeIndex.value === -1
                ? (delta > 0 ? 0 : count - 1)
                : (activeIndex.value + delta + count) % count;
    };

    /** Follow a target, then let the host know — which is how the overlay closes itself. */
    const visit = (href: string): void => {
        router.visit(href);
        options.onNavigate?.();
    };

    const clear = (): void => {
        query.value = "";
        forget();
    };

    /**
     * The keyboard, for the box this is wired to.
     *
     * ENTER ON NOTHING IS LEFT ALONE — no `preventDefault` — because the reader has not chosen a
     * row, and swallowing the key there would make a field that silently eats the most obvious
     * thing to press. ESCAPE is not swallowed either, and that is load-bearing rather than
     * lenient: in the header the field lives inside a native `[popover]`, whose light dismiss IS
     * Escape, so preventing it would leave the overlay open with an empty field.
     */
    const onKeydown = (event: KeyboardEvent): void => {
        if (event.key === "ArrowDown") {
            event.preventDefault();
            move(1);

            return;
        }

        if (event.key === "ArrowUp") {
            event.preventDefault();
            move(-1);

            return;
        }

        if (event.key === "Enter") {
            const target = targets.value[activeIndex.value];
            if (target === undefined) return;

            event.preventDefault();
            visit(target.href);

            return;
        }

        if (event.key === "Escape") clear();
    };

    // A pending request outliving its box would paint into a component that is gone, and a
    // pending debounce would fire the request after it. Guarded because a spec may call this
    // outside any component, where there is no scope to dispose.
    if (getCurrentScope()) onScopeDispose(forget);

    return {
        query,
        scope,
        groups,
        loading,
        failed,
        active,
        tooShort,
        listboxId,
        activeOptionId,
        onKeydown,
        noteNavigation: () => options.onNavigate?.(),
        clear
    };
};
