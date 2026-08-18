<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Artist;
use App\Models\User;
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
            // wrong: this listing deliberately shows artists that perform nothing — a compilation
            // owner named on the sleeve with none of their own recordings on it — and an artist a
            // reader cannot play is not one they have never played. Their tile would be a link to
            // rows nobody can act on.
            self::NeverPlayed => $query
                ->whereHas('tracks', fn (Builder $tracks) => $tracks->where('tracks.type', TrackType::Music))
                ->whereNotExists(function (QueryBuilder $plays) use ($reader) {
                    $plays->selectRaw('1')
                        ->from('plays')
                        ->join('tracks', 'plays.track_id', '=', 'tracks.id')
                        ->where('tracks.type', TrackType::Music)
                        ->whereColumn('tracks.artist_id', 'artists.id');

                    // A guest has no listening history, so every artist is one they have never played
                    // — the reading PlayCounts::scopedToReader spells the same way.
                    if ($reader === null) {
                        $plays->whereRaw('1 = 0');
                    } else {
                        $plays->where('plays.user_id', $reader->id);
                    }
                }),

            // NO ALBUM OF THEIR OWN, BUT SONGS SOMEWHERE. Both halves are needed, and the relations
            // they read are not the same relation: `albums` is what the artist is the ALBUM-ARTIST
            // of, `tracks` is what they PERFORM, and conflating the two is the trap sharing.md
            // records (`tracks.artist_id` is not `collections.album_artist_id`). Without the
            // `tracks` half this would also collect the opposite oddity — a compilation owner
            // credited on the sleeve with nothing of their own on it.
            self::CompilationsOnly => $query
                ->whereDoesntHave('albums')
                ->whereHas('tracks', fn (Builder $tracks) => $tracks->where('tracks.type', TrackType::Music)),

            // The FILE's mtime, never a row's `created_at`: a row timestamp is a fact about the
            // database and is re-stamped wholesale when the library tables are rebuilt (SongFilter
            // carries the measurement). An artist is new when something of theirs is.
            self::AddedThisMonth => $query->whereHas('tracks', fn (Builder $tracks) => $tracks
                ->where('tracks.type', TrackType::Music)
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
