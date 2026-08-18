<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The four questions the Genres listing's stats strip counts — and the same four a `?filter=`
 * narrows its table by. SongFilter's shape at the genre grain; that class carries the argument for
 * one predicate serving both the tile's count and the table's filter.
 *
 * {@see OneArtist} IS THE ONE THIS STRIP EXISTS FOR, and it is not the listing's `artists` column
 * asked with a filter. That column counts artists whose MAIN genre this is (DominantGenre), which is
 * a question about where an artist mostly sits; this counts the distinct performers of the genre's
 * own songs, which is a question about the genre. The two disagree by design — a genre can hold two
 * hundred songs by one band and be nobody's main genre, or be several artists' main genre while its
 * own songs come from one of them. Measured on the live library, 74 of 140 genres are one artist's,
 * and no sort of the existing column finds them.
 *
 * EVERY PREDICATE IS SCOPED TO MUSIC, and here that is not belt-and-braces: only AUDIOBOOKS are
 * barred by the tracks CHECK from carrying a genre, so an audiobook CHAPTER legally can — and a book
 * tagged "Hörspiel" would otherwise turn up in a music listing's counts.
 */
enum GenreFilter: string
{
    /** Genres THIS READER has never played anything from. */
    case NeverPlayed = 'never-played';

    /** Genres whose songs are all by one performer — a genre that is really one band's tag. */
    case OneArtist = 'one-artist';

    /** Genres holding a file a week old or newer — {@see WEEK_DAYS}. */
    case AddedThisWeek = 'added-this-week';

    /** Genres holding exactly one song — the long tail of typos and one-off tags. */
    case OneSong = 'one-song';

    /**
     * How long "this week" is — the songs and albums window, rolling for the reason SongFilter
     * gives. (The artists strip uses a month instead: an artist is a coarser thing than a file.)
     */
    private const WEEK_DAYS = 7;

    /**
     * Narrow a query over genres to the rows this filter is about.
     *
     * @param  Builder<Genre>  $query  a query whose base table is `genres`
     * @param  User|null  $reader  whose listening history decides {@see NeverPlayed}
     * @return Builder<Genre> the same query, narrowed
     */
    public function apply(Builder $query, ?User $reader): Builder
    {
        return match ($this) {
            // WITH SOMETHING TO PLAY, which is the half a bare "no plays exist" predicate gets
            // wrong: this listing deliberately shows genres that hold no music — one whose songs
            // were all pruned, or one carried only by an audiobook chapter — and a genre a reader
            // cannot play is not one they have never played.
            self::NeverPlayed => $query
                ->whereHas('tracks', fn (Builder $tracks) => $tracks->where('tracks.type', TrackType::Music))
                ->whereNotExists(function (QueryBuilder $plays) use ($reader) {
                    $plays->selectRaw('1')
                        ->from('plays')
                        ->join('tracks', 'plays.track_id', '=', 'tracks.id')
                        ->where('tracks.type', TrackType::Music)
                        ->whereColumn('tracks.genre_id', 'genres.id');

                    if ($reader === null) {
                        $plays->whereRaw('1 = 0');
                    } else {
                        $plays->where('plays.user_id', $reader->id);
                    }
                }),

            // GROUPED, not a correlated `count(distinct …)` per row: this aggregates the tracks
            // table once and semi-joins the result, the trade PlayCounts::ownCountForArtist
            // measured. A genre whose songs credit NOBODY counts zero distinct artists rather than
            // one — `count(distinct)` skips NULLs — so it falls out, which is right: "one artist"
            // is a claim about who that artist is.
            self::OneArtist => $query->whereIn('genres.id', function (QueryBuilder $single) {
                $single->select('tracks.genre_id')
                    ->from('tracks')
                    ->where('tracks.type', TrackType::Music)
                    ->whereNotNull('tracks.genre_id')
                    ->groupBy('tracks.genre_id')
                    ->havingRaw('count(distinct tracks.artist_id) = 1');
            }),

            // The FILE's mtime, never a row's `created_at` — SongFilter carries why.
            self::AddedThisWeek => $query->whereHas('tracks', fn (Builder $tracks) => $tracks
                ->where('tracks.type', TrackType::Music)
                ->where('tracks.modified_at', '>=', now()->subDays(self::WEEK_DAYS))
            ),

            // `has` with a count AND a constraint, which is the only spelling that expresses both
            // halves: exactly one track, counting music alone. Without the callback a book chapter
            // tagged with a genre would be counted as one of its songs.
            self::OneSong => $query->has(
                'tracks',
                '=',
                1,
                'and',
                fn (Builder $tracks) => $tracks->where('tracks.type', TrackType::Music)
            ),
        };
    }

    /**
     * How many genres this filter leaves — the number its tile shows.
     *
     * Over every genre, matching the listing's own population: that table is deliberately not
     * filtered to genres with tracks (GenresController says why), so a strip that counted only
     * tagged ones would put a total above the table that the table disagrees with.
     */
    public function count(?User $reader): int
    {
        return $this->apply(Genre::query(), $reader)->count();
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
