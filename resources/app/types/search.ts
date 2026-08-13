/******************************************************************************
 * Search — the JSON shapes `GET /search` answers with (App\Http\Controllers\SearchController)
 * and the client-side scope the chips choose between.
 *
 * NOT an Inertia prop type, unlike everything in `music.ts`, and that is the one thing worth
 * knowing before using it: search is the app's third `fetch` endpoint rather than a page's
 * props, because a typeahead that re-rendered the page on every keystroke is the documented way
 * to lose what a reader has typed (CLAUDE.md → the prefetch rule). So these shapes arrive from
 * a request this code makes, and nothing here reaches a page component as a prop.
 *
 * Every value is RAW, per the app's rule: `count` is a number the client pluralises against the
 * group's own kind, never a phrase the server composed — "12 Alben" built in PHP would be German
 * on a page being read in English.
 *****************************************************************************/

/** The kinds the engine can answer with, in the fixed group order (App\Enums\SearchKind). */
export type SearchKind = "artist" | "album" | "playlist" | "song" | "genre" | "audiobook";

/**
 * What the Music page's chips choose between: one kind, or all of them.
 *
 * `all` is a client-side word — the endpoint says "every kind" by sending no `kinds` at all —
 * because a radio group needs a value for "no filter" and an empty string is not a state a
 * reader can be shown as chosen.
 */
export type SearchScope = "all" | SearchKind;

/**
 * A fact a row can carry, as the server names it (`App\Services\Search\SearchHit`).
 *
 * Which two a kind sends is fixed per kind; which ICON and label stand for each, and the order
 * they are drawn in, belong to SearchResults — so this is only the vocabulary the two ends share.
 */
export type SearchFactKey = "albums" | "artists" | "artist" | "songs" | "tracks" | "duration";

/**
 * The raw facts of one row: counts as numbers, runtimes as SECONDS, a credit as a name.
 *
 * Raw because the client formats — a clock, a locale's thousands separator — and because "12
 * Alben" composed in PHP would be German on a page being read in English. A key that is absent or
 * null is a fact the row does not have, and its pip is simply not drawn: an artist credited on
 * albums but performing no tracks, or a file whose tags carried no duration, are both real, and
 * "0:00" reads as a broken row rather than as an absence.
 */
export type SearchFacts = Partial<Record<SearchFactKey, number | string | null>>;

/** One result row: what to call it, the two facts that tell it apart, and where it goes. */
export interface SearchRow {
    /** The subject's UUID — the list's `:key`, in a list that repaints per keystroke. */
    id: string;
    /** The matched name, exactly as it is stored. */
    name: string;
    /** The row's own page. Decided server-side, like every link in this app. */
    href: string;
    /** The kind's own two facts, raw — see {@link SearchFacts}. */
    facts: SearchFacts;
}

/** One kind's answer — see `App\Services\Search\SearchGroup` for why `total` is not `rows.length`. */
export interface SearchGroup {
    kind: SearchKind;
    /** Every match for this kind, not just the few carried in `rows`. */
    total: number;
    /** The top five, already ranked by the server (SearchRanking). */
    rows: SearchRow[];
    /**
     * The listing at `?search=…` — where the WIDE search still lives, matching artist, album
     * and genre as well. Null when there is nothing more to see, and always null for playlists,
     * whose listing is a hand-ordered list with no search of its own.
     */
    seeAll: string | null;
}

/** The endpoint's envelope. Groups with nothing in them are dropped rather than sent empty. */
export interface SearchResponse {
    groups: SearchGroup[];
}

/**
 * One thing the arrow keys can land on — a result row, or a group's "see all" link.
 *
 * THE HAND-OFF IS WALKABLE TOO, deliberately: it is the answer for a reader whose match is the
 * seventy-eighth song, and a keyboard that could reach every row but not the way out of the
 * group would be a keyboard that stops short of the useful part.
 */
export interface SearchTarget {
    /** Stable within one mounting — `${kind}-${id}`, or `${kind}-all` for a hand-off. */
    key: string;
    href: string;
}
