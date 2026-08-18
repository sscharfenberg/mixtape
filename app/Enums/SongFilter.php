<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The four questions the Songs listing's stats strip counts — and the same four a `?filter=`
 * narrows its table by.
 *
 * ONE PREDICATE PER FILTER, SERVING BOTH. A tile says "23" and its link has to land on exactly
 * those 23 rows; written twice — a count here, a `where` in the controller — the two drift on
 * the first change, and the drift reads as a wrong NUMBER rather than as a wrong filter, which
 * is the harder bug to see. So {@see apply} is the only place a filter is defined and
 * {@see count} is that same predicate over a bare count query. It is the rule ShareGrant's
 * `tracks()` / `contains()` pair exists for, in a smaller place.
 *
 * EVERY COLUMN IS QUALIFIED, because `apply` is handed two different queries: the listing's,
 * which joins artists / collections / genres, and the count's, which joins nothing. An
 * unqualified `cover` or `created_at` is one join away from being ambiguous — and Postgres says
 * so at the moment a reader clicks, where sqlite may not say it at all.
 *
 * THE CASES ARE THE ORDER the strip draws them in, the way SearchKind's are: adding a question
 * to this listing is a case and a translation, not a branch somewhere.
 */
enum SongFilter: string
{
    /** Songs THIS READER has never played — the complement of the popular widget, and a queue to work through. */
    case NeverPlayed = 'never-played';

    /** Songs the scanner first saw within the last week — see {@see WEEK_DAYS} for what "week" means. */
    case AddedThisWeek = 'added-this-week';

    /** Songs whose audio is byte-identical to another song's — the same recording filed twice. */
    case Duplicated = 'duplicates';

    /** Songs whose file carries no embedded picture, so the player has nothing of its own to show. */
    case Uncovered = 'no-cover';

    /**
     * How long "this week" is.
     *
     * A ROLLING WINDOW, not the calendar week: a Monday boundary makes the tile read 0 every
     * Monday morning, which is a fact about the calendar rather than about the library. Seven
     * days back from now always answers the question a reader is asking, which is "what is new".
     */
    private const WEEK_DAYS = 7;

    /**
     * Narrow a query over music tracks to the rows this filter is about.
     *
     * Takes the query rather than building one, because the caller's query is what differs: the
     * listing arrives with three joins and a select, the count with neither. What must NOT
     * differ is the predicate, which is why this method exists at all (see the class docblock).
     *
     * @param  Builder<Track>  $query  a query whose base table is `tracks`, already scoped to music
     * @param  User|null  $reader  whose listening history decides {@see NeverPlayed}
     * @return Builder<Track> the same query, narrowed
     */
    public function apply(Builder $query, ?User $reader): Builder
    {
        return match ($this) {
            self::NeverPlayed => $query->whereNotExists(function (QueryBuilder $plays) use ($reader) {
                $plays->selectRaw('1')->from('plays')->whereColumn('plays.track_id', 'tracks.id');

                // Somebody who is not signed in has no listening history at all, so every song
                // is one they have never played — an impossible predicate rather than a second
                // return, which is how PlayCounts::scopedToReader spells the same reading.
                if ($reader === null) {
                    $plays->whereRaw('1 = 0');
                } else {
                    $plays->where('plays.user_id', $reader->id);
                }
            }),

            // `tracks.created_at` is when the SCANNER first inserted the row, which is what
            // "added" means here — not the file's own mtime (`modified_at`), which a re-tag
            // moves without the library gaining anything. The steady-state scan leaves an
            // untouched file's row alone, so these timestamps survive every `app:update`.
            self::AddedThisWeek => $query->where('tracks.created_at', '>=', now()->subDays(self::WEEK_DAYS)),

            // GROUPED, not a per-row EXISTS over `Track::clones`. Both answer the same set, and
            // the shapes cost differently at this scale: the correlated one probes the
            // content_hash index once per candidate row over the whole table, where this
            // aggregates the hashes once and semi-joins the result — the trade
            // PlayCounts::ownCountForArtist measured for play counts, reaching the same answer.
            //
            // ALIASED, so `clones.type` cannot be read as the outer `tracks.type`: an unaliased
            // self-join leaves the qualified name binding to the inner table by chance rather
            // than by intent, and a duplicate is a duplicate WITHIN music (a chapter that
            // happens to share a hash with a song is not a song filed twice).
            self::Duplicated => $query->whereIn('tracks.content_hash', function (QueryBuilder $shared) {
                $shared->select('clones.content_hash')
                    ->from('tracks as clones')
                    ->where('clones.type', TrackType::Music)
                    ->groupBy('clones.content_hash')
                    ->havingRaw('count(*) > 1');
            }),

            // The EMBEDDED picture, which is what `tracks.cover` records — an album's
            // `Folder.jpg` lives on the collection and covers its songs on a page, but a song
            // pulled out of its album into a queue or a playlist has only its own file to
            // offer. So this counts songs that travel without artwork.
            self::Uncovered => $query->where('tracks.cover', false),
        };
    }

    /**
     * How many music tracks this filter leaves — the number its tile shows.
     *
     * The same {@see apply} the listing uses, over a query with no joins and no select, so the
     * count and the filtered table cannot disagree.
     */
    public function count(?User $reader): int
    {
        return $this->apply(
            Track::query()->where('tracks.type', TrackType::Music),
            $reader,
        )->count();
    }

    /**
     * The filter a request asks for, or null for one that asks for none — or for nonsense.
     *
     * FALLING BACK RATHER THAN REFUSING, which is deliberate and matches how DataTableService
     * treats `sort`, `dir` and `search`: the query string is this table's state and readers pass
     * whole URLs around, so a stale or hand-edited `?filter=` should show the unfiltered listing
     * rather than a 422. That is why this is not a FormRequest, against the house rule.
     *
     * The `is_string` guard is not belt-and-braces: `?filter[]=x` arrives as an ARRAY, and
     * `tryFrom` typed against `string` would make a hand-written URL a 500 — the same trap
     * DataTableService documents for `?search[]=`.
     */
    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
