<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Models\User;
use App\Services\Search\Contracts\SearchableKind;
use App\Services\Search\FoldedSearch;
use App\Services\Search\SearchGroup;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchRanking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the five kinds do identically: match, rank, limit, count, shape.
 *
 * A base class rather than five copies, because the parts that must not drift are exactly the
 * parts that are the same everywhere — most of all THE CENTRAL RULE, that a row matches its
 * OWN name (docs/search.md → "What a match is"). A song is not returned because its artist
 * matched; the artist is, as an artist. That rule lives in one place here: whatever a subclass
 * names in {@see matched()} is all it can be found by, and the numbers say why the narrow
 * reading wins — searching "black" is 77 songs by title against 1,238 once artist, album and
 * genre count, a tenth of the library, almost all of it "Black Metal" as a genre dragging in
 * every track filed under it.
 *
 * EVERY MATCH GOES THROUGH FoldedSearch, never through raw SQL folding, and that is not a
 * preference: `artists.name` carries the nondeterministic `case_insensitive` ICU collation and
 * Postgres refuses LIKE / ILIKE / regex on it, so a search written against the raw columns is a
 * hard 500 on one table and unrunnable on the sqlite test database. The `_fold` companions carry
 * the default collation and one code path.
 *
 * THE COUNT IS SKIPPED WHERE THE ROWS ALREADY ANSWER IT. A group that did not fill its limit
 * IS its own total, so only a full page of rows costs a second query — which on a typeahead
 * firing per keystroke is the difference between ten queries a request and six.
 */
abstract class DatabaseKind implements SearchableKind
{
    /**
     * This kind's answer: the real total, the top `$limit` rows in ranked order, and a
     * hand-off only when there is more to see than is being shown.
     */
    public function group(string $query, User $reader, int $limit): SearchGroup
    {
        $rows = $this->matches($query, $reader);
        SearchRanking::apply($rows, $this->ranked(), $query, $this->tieBreak());

        $hits = $rows->limit($limit)->get()
            ->map(fn (Model $row): SearchHit => $this->hit($row))
            ->values()
            ->all();

        // A partial page is its own total — see the class note. Only a full one has to ask.
        $total = count($hits) < $limit
            ? count($hits)
            : $this->matches($query, $reader)->count();

        return new SearchGroup(
            kind: $this->kind(),
            total: $total,
            rows: $hits,
            seeAll: $total > $limit ? $this->seeAll($query) : null,
        );
    }

    /**
     * The kind's own query with the search applied — built fresh per call, because both
     * `SearchRanking::apply` and `count()` mutate what they are handed.
     */
    private function matches(string $query, User $reader): Builder
    {
        $builder = $this->query($reader);
        FoldedSearch::apply($builder, $query, $this->matched());

        return $builder;
    }

    /**
     * The rows this kind may return, before any search — its table, its scope, and whatever
     * the row's second line needs selected.
     *
     * Where the `type` narrowings live for the two unified tables: `tracks` and `collections`
     * hold audiobook chapters and audiobooks beside music, and an audiobook chapter answering
     * a music search is the same class of bug `AuthorizesMusicTrack` and `StoreShareRequest`
     * exist to prevent.
     */
    abstract protected function query(User $reader): Builder;

    /**
     * The RAW columns a row may be found by — named raw (`tracks.name`), matched through their
     * `_fold` siblings by FoldedSearch.
     *
     * Almost always exactly one, the row's own name. The class note says why that is the
     * feature's central rule rather than a limitation.
     *
     * @return list<string>
     */
    abstract protected function matched(): array;

    /**
     * The raw column the four ranking tiers and the A→Z order read.
     *
     * Separate from {@see matched()} because a playlist is matched on its blurb as well but
     * ranked on its name alone — a playlist found only by its description lands in the
     * "anywhere else" tier, which is the honest place for it.
     */
    abstract protected function ranked(): string;

    /** The column that makes the order TOTAL — an id, so no two rows can tie. See SearchRanking. */
    abstract protected function tieBreak(): string;

    /** One row → one hit: its name, its page, and the one extra fact its second line prints. */
    abstract protected function hit(Model $row): SearchHit;

    /**
     * The listing to hand off to at `?search=…`, or null for a kind that has none.
     *
     * This is where the WIDE search still lives: the four Music listings each match several
     * columns, sorted, paginated and deep-linkable, so "see all in Songs" is both behaviours
     * kept — narrow while you are typing, wide once you have committed to a kind. Only
     * playlists answer null, their listing being a hand-ordered list rather than a DataTable.
     */
    abstract protected function seeAll(string $query): ?string;
}
