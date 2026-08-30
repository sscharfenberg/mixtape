<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Artist;
use App\Models\User;
use App\Services\Music\ArtistCredits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The four questions the Artists listing's stats strip counts — and the same four a `?filter=`
 * narrows its table by. SongFilter's shape at the artist grain; that class carries the argument for
 * one predicate serving both the tile's count and the table's filter.
 *
 * TWO OF THEM ARE INVISIBLE IN THE LISTING, which is the test a tile has to pass here: every column
 * the table shows is sortable, so "most albums", "most songs" and "most played" are a header click
 * away and a tile for any of them would be a sort in tile clothing (docs/browse-stats.md). What no
 * column can express is {@see LookalikeName} — a credit that is probably several artists — and
 * {@see AddedThisMonth}, since the listing has no date at all.
 *
 * EVERY PREDICATE IS SCOPED TO MUSIC, belt-and-braces in the same way the listing's own aggregates
 * are: the type CHECK forbids an audiobook chapter from carrying an artist today, so the clause
 * changes nothing — and it is what keeps these numbers right the day a kind that CAN carry one is
 * added.
 */
enum ArtistFilter: string
{
    /** Artists THIS READER has never played a note of. */
    case NeverPlayed = 'never-played';

    /** Artists with no album of their own — you own them only as a guest on somebody else's. */
    case CompilationsOnly = 'compilations-only';

    /** Artists holding a file a month old or newer — {@see MONTH_DAYS}. */
    case AddedThisMonth = 'added-this-month';

    /** Artists whose NAME looks like several artists jammed into one tag. */
    case LookalikeName = 'lookalike-name';

    /**
     * How long "this month" is.
     *
     * A MONTH RATHER THAN THE WEEK the songs and albums strips use, because an artist is a coarser
     * thing than a file: a week of listening brings new songs constantly and new artists rarely, so
     * the same window that reads 43 songs reads a handful of artists and looks broken. Measured on
     * the live library: 41 artists over seven days against 53 over thirty. Rolling, for the reason
     * SongFilter gives — a calendar boundary makes the tile read 0 every Monday.
     */
    private const MONTH_DAYS = 30;

    /**
     * The separators that make one credit look like several.
     *
     * A CURATED LIST, and it is the whole definition of {@see LookalikeName} — "Massive Attack vs
     * Mad Professor", "Nick Cave & The Bad Seeds", "Jóhann Jóhannsson, Hildur Guðnadóttir & The
     * Cinema Orchestra". Two of those three are the artist's real name, which is exactly why the
     * tile counts CANDIDATES rather than faults: the reader decides, in their tagger.
     *
     * `LIKE` rather than a regular expression, for two reasons that both bite. `name_fold` carries a
     * deterministic collation and `name` does not, so Postgres refuses a regex (and a `LIKE`)
     * against the raw column outright — measured, `nondeterministic collations are not supported for
     * regular expressions`. And sqlite, which the test suite runs, has no regex operator at all, so
     * a `~` predicate could not be tested. A handful of ORs over 600 rows costs nothing.
     *
     * Matched against `name_fold`, which is already lower-cased and accent-stripped, so the patterns
     * are lower-case and need no case handling of their own.
     */
    private const LOOKALIKE_PATTERNS = ['% feat%', '% ft.%', '% vs%', '% with %', '%, %', '% & %', '%/%'];

    /**
     * Narrow a query over artists to the rows this filter is about.
     *
     * @param  Builder<Artist>  $query  a query whose base table is `artists`
     * @param  User|null  $reader  whose listening history decides {@see NeverPlayed}
     * @return Builder<Artist> the same query, narrowed
     */
    public function apply(Builder $query, ?User $reader): Builder
    {
        return match ($this) {
            // WITH SOMETHING TO PLAY, which is the half a bare "no plays exist" predicate gets
            // wrong: this listing deliberately shows every artist, credited with a playable file
            // or not, and an artist a reader CANNOT play is not one they have never played. Their
            // tile would be a link to rows nobody can act on.
            self::NeverPlayed => self::withCreditedMusic($query)
                // The COMPLEMENT of the same set: artists credited with something this reader has
                // a `plays` row for. `whereNotIn` rather than a correlated `NOT EXISTS` for the
                // reason ArtistCredits::artistIds gives — and it is safe here because that set can
                // never contain a NULL, which `NOT IN` would answer with no rows at all.
                ->whereNotIn('artists.id', ArtistCredits::artistIds(
                    function (QueryBuilder $tracks) use ($reader) {
                        $tracks->join('plays', 'plays.track_id', '=', 'tracks.id')
                            ->where('tracks.type', TrackType::Music);

                        // A guest has no listening history, so every artist is one they have never
                        // played — the reading PlayCounts::scopedToReader spells the same way.
                        $reader === null
                            ? $tracks->whereRaw('1 = 0')
                            : $tracks->where('plays.user_id', $reader->id);
                    }
                )),

            // NO ALBUM OF THEIR OWN, BUT SONGS SOMEWHERE. Both halves are needed: `albums` is
            // what the artist is the ALBUM-ARTIST of, and without the second half this would
            // also collect the opposite oddity — a name credited on a sleeve with no playable
            // file behind it at all.
            //
            // The second half narrows by CREDITS like every other tile here, and for an artist
            // that passes the first it is provably the same set as "what they perform": an
            // artist with no album of their own is named by no `album_artist_id`, so the credit
            // union's second arm is empty for them. Written the same way regardless, because a
            // strip whose tiles count two different things is the drift these predicates exist
            // to prevent.
            self::CompilationsOnly => self::withCreditedMusic($query->whereDoesntHave('albums')),

            // The FILE's mtime, never a row's `created_at`: a row timestamp is a fact about the
            // database and is re-stamped wholesale when the library tables are rebuilt (SongFilter
            // carries the measurement). An artist is new when something of theirs is.
            self::AddedThisMonth => self::withCreditedMusic(
                $query, fn (QueryBuilder $tracks) => $tracks
                    ->where('tracks.modified_at', '>=', now()->subDays(self::MONTH_DAYS))
            ),

            self::LookalikeName => $query->where(function (Builder $name) {
                foreach (self::LOOKALIKE_PATTERNS as $pattern) {
                    $name->orWhere('artists.name_fold', 'like', $pattern);
                }
            }),
        };
    }

    /**
     * How many artists this filter leaves — the number its tile shows.
     *
     * Over every artist, matching the listing's own population: that table is deliberately NOT
     * filtered to artists with tracks (ArtistsController says why), so a strip that counted only
     * performing ones would put a total above the table that the table disagrees with.
     */
    public function count(?User $reader): int
    {
        return $this->apply(Artist::query(), $reader)->count();
    }

    /**
     * Narrow a query over artists to those credited with at least one music track — the
     * "something to play" half two of these tiles need, and the hook the third narrows
     * further.
     *
     * CREDITED, not performed: an artist owns what sits on a record credited to them as well
     * as what they perform (App\Services\Music\ArtistCredits), which is the same set the
     * listing's `songs` column counts and the artist's own page lists. A tile pointing at rows
     * counted by a different rule is a link to a number the table then disagrees with.
     *
     * A SEMI-JOIN against the set of credited artists, never a correlated probe per row — the
     * strip runs four of these on every page load, and the correlated spelling costs 29 seconds
     * a tile (ArtistCredits::artistIds carries the measurement). Either way an artist credited
     * with two hundred tracks still produces one row of the listing.
     *
     * @param  Builder<Artist>  $query  a query whose base table is `artists`
     * @param  (callable(QueryBuilder): mixed)|null  $narrow  applied to the `tracks` query
     *                                                        inside, for a tile that wants a
     *                                                        subset of what is playable
     * @return Builder<Artist> the same query, narrowed
     */
    private static function withCreditedMusic(Builder $query, ?callable $narrow = null): Builder
    {
        return $query->whereIn('artists.id', ArtistCredits::artistIds(
            function (QueryBuilder $tracks) use ($narrow): void {
                $tracks->where('tracks.type', TrackType::Music);

                if ($narrow !== null) {
                    $narrow($tracks);
                }
            }
        ));
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
