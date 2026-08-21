<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The four questions the Albums listing's stats strip counts — and the same four a `?filter=`
 * narrows its table by. SongFilter's shape, at the album grain.
 *
 * TWO OF THEM ARE QUESTIONS ONLY AN ALBUM CAN BE ASKED, and they are the reason this strip is
 * worth its space: a gap in the track numbering (a rip that never finished) and an album holding
 * one track (usually a loose file that got a folder of its own). Neither is visible in the
 * listing — every column it shows is per-album and sortable, so "most played" and "longest" are
 * a header click away, while "incomplete" needs opening albums one at a time to find.
 *
 * NO `type = music` CLAUSE ANYWHERE, unlike SongFilter, and the reason is worth stating because
 * the database does not enforce it: the CHECK constraints police the enum VALUES of
 * `collections.type` and `tracks.type` separately, not that a track's type matches its
 * collection's. What settles it is the scanner — an album holds music and a book holds chapters
 * — the same invariant PlayCounts::forAlbum leans on when it counts an album's plays without a
 * type clause. Stated once here so both read the same way; if that invariant ever breaks, these
 * numbers are wrong together rather than disagreeing with each other.
 *
 * ONE PREDICATE PER FILTER, SERVING BOTH the tile's count and the table's filter — see
 * SongFilter, which carries the whole argument.
 */
enum AlbumFilter: string
{
    /** Albums THIS READER has not played a single track of. */
    case NeverPlayed = 'never-played';

    /** Albums holding a file a week old or newer — {@see WEEK_DAYS}, and see apply() for which date. */
    case AddedThisWeek = 'added-this-week';

    /** Albums MISSING at least one track, by their own numbering. */
    case Incomplete = 'incomplete';

    /** Albums holding exactly one track. */
    case SingleTrack = 'single-track';

    /**
     * How long "this week" is — a rolling window, for the reason SongFilter gives: a calendar
     * boundary makes the tile read 0 every Monday, which is a fact about the calendar.
     */
    private const WEEK_DAYS = 7;

    /**
     * Narrow a query over albums to the rows this filter is about.
     *
     * @param  Builder<Collection>  $query  a query whose base table is `collections`, already scoped to albums
     * @param  User|null  $reader  whose listening history decides {@see NeverPlayed}
     * @return Builder<Collection> the same query, narrowed
     */
    public function apply(Builder $query, ?User $reader): Builder
    {
        return match ($this) {
            // Through `tracks`, because a play belongs to a FILE: an album is played when
            // anything on it has been. `whereNotExists` rather than a count of 0, so the engine
            // may stop at the first play it finds rather than counting them all.
            self::NeverPlayed => $query->whereNotExists(function (QueryBuilder $plays) use ($reader) {
                $plays->selectRaw('1')
                    ->from('plays')
                    ->join('tracks', 'plays.track_id', '=', 'tracks.id')
                    ->whereColumn('tracks.collection_id', 'collections.id');

                // A guest has no listening history, so every album is one they have never
                // played — PlayCounts::scopedToReader spells the same reading this way.
                if ($reader === null) {
                    $plays->whereRaw('1 = 0');
                } else {
                    $plays->where('plays.user_id', $reader->id);
                }
            }),

            // AN ALBUM IS NEW WHEN ONE OF ITS FILES IS, read off the file's own mtime rather than
            // the collection row's `created_at`. That column is a fact about the DATABASE — the
            // scanner stamps it on insert, so rebuilding the library tables re-stamps every album
            // at once and the tile answers "all 925 arrived this week", which is what the dev box
            // did four days after its rebuild. Mtime survives a rebuild, and answered 7.
            //
            // SongFilter carries the trade this accepts (a re-tag reads as new) and why it is the
            // smaller lie of the two.
            self::AddedThisWeek => $query->whereExists(fn (QueryBuilder $tracks) => $tracks
                ->selectRaw('1')
                ->from('tracks')
                ->whereColumn('tracks.collection_id', 'collections.id')
                ->where('tracks.modified_at', '>=', now()->subDays(self::WEEK_DAYS))
            ),

            // A GAP IN THE NUMBERING, asked PER DISC, which is the whole subtlety: numbering
            // restarts on disc 2, so an album of two ten-track discs has a highest number of 10
            // against twenty files, and comparing album-wide would call every multi-disc set
            // incomplete. Grouped by (collection_id, disc), an album is incomplete when ANY of
            // its discs numbers HIGHER than the number of files it holds — the album says it has
            // ten tracks and nine are here, so one is missing whichever one it is.
            //
            // STRICTLY GREATER, not merely different, and that is a diagnosis rather than a
            // tidy-up. The other direction — MORE files than the numbering reaches — is not a
            // missing track at all but a repeated number: measured on the live library, 74 albums
            // number higher than their file count (genuinely missing something) against 4 that
            // number lower, and all four of those are duplicate numbering (a reissue whose bonus
            // discs all claim disc 1, an album with two track 4s). Reporting those as
            // "incomplete" sends a reader looking for a file that was never missing. `app:audit`
            // reports both, in separate sections, over this same predicate.
            //
            // `max(track) IS NOT NULL` keeps a rip whose files carry no track numbers at all out
            // of it: the comparison against NULL is unknown rather than false, so the row would
            // fall out anyway — saying so makes it a decision rather than an accident. "Not
            // numbered at all" is a third fault, and one tile cannot mean three things.
            self::Incomplete => $query->whereIn('collections.id', function (QueryBuilder $gapped) {
                $gapped->select('tracks.collection_id')
                    ->from('tracks')
                    ->whereNotNull('tracks.collection_id')
                    ->groupBy('tracks.collection_id', 'tracks.disc')
                    ->havingRaw('max(tracks.track) is not null and max(tracks.track) > count(*)');
            }),

            // `has` with a count, which is Eloquent's own spelling of the question and reads as
            // the question: an album with exactly one track. A correlated count per album, and
            // right to be — it runs over the albums a page is drawing rather than aggregating
            // every track in the library to answer a WHERE.
            self::SingleTrack => $query->has('tracks', '=', 1),
        };
    }

    /**
     * How many albums this filter leaves — the number its tile shows.
     *
     * The same {@see apply} the listing uses, over a query with no joins and no select.
     */
    public function count(?User $reader): int
    {
        return $this->apply(
            Collection::query()->where('collections.type', CollectionType::Album),
            $reader,
        )->count();
    }

    /**
     * The filter a request asks for, or null for one that asks for none — or for nonsense.
     *
     * Falls back rather than refusing, and guards against an ARRAY value: SongFilter::fromInput
     * carries both arguments.
     */
    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
