<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Track;

/**
 * The two sets of numbers that describe the library — one for the music, one for the
 * audiobooks. Exactly what the two stats cards draw, and nothing else.
 *
 * IT IS A SERVICE BECAUSE THREE PAGES ASK NOW. Each set lived as a private method on the
 * controller of the area it described, which was right while each was asked once. The welcome
 * page (2026-08-14) shows BOTH cards to a visitor who has no account, so a second copy of each
 * query would exist — and two copies of "how big is this collection" are two answers waiting to
 * disagree the day one of them learns a new rule (the `has('tracks')` filters below are exactly
 * that kind of rule, and each was a bug once).
 *
 * EVERY NUMBER IS RAW, per the app's server-sends-raw rule: bytes and seconds, never a
 * formatted size or a clock. The cards format them, because only the client knows the locale.
 *
 * Nothing here is per-viewer — there is no reader argument and no `plays` in sight. That is
 * what lets the welcome page call it for a guest.
 */
class LibraryStats
{
    /**
     * The music collection's totals.
     *
     * MUSIC ONLY, matching the browse widgets it sits above: artists and genres are restricted
     * to those that actually have tracks, exactly as their listings are. That filter is not
     * tidiness — an artist can exist as an album-artist alone (a compilation owner whose songs
     * credit the individual performers), and counting those would report more artists than the
     * artist listing shows.
     *
     * `size` / `duration` are cast so a null SUM on an empty library becomes 0 rather than null.
     *
     * The year range is ALBUMS only — see {@see years} for what the pair means and why it is a
     * pair rather than a string.
     *
     * @return array{songs: int, sizeBytes: int, playtimeSeconds: float, albums: int, artists: int, genres: int, firstYear: int|null, lastYear: int|null}
     */
    public static function music(): array
    {
        $music = fn () => Track::query()->where('type', TrackType::Music);

        return [
            'songs' => $music()->count(),
            'sizeBytes' => (int) $music()->sum('size'),
            'playtimeSeconds' => (float) $music()->sum('duration'),
            'albums' => Collection::query()->where('type', CollectionType::Album)->count(),
            'artists' => Artist::query()->has('tracks')->count(),
            'genres' => Genre::query()->has('tracks')->count(),
            ...self::years(CollectionType::Album),
        ];
    }

    /**
     * The audiobook collection's totals: books, chapters, size, authors, narrators, playtime.
     *
     * Authors and narrators are counted over TAXONOMY ROWS rather than distinct values on
     * tracks, which is the same thing given the scanner prunes orphans on every run — and one
     * index-only count instead of a scan of the chapter table.
     *
     * Cast for the same empty-library reason {@see music} carries. The year range is BOOKS only,
     * the mirror of the music card's albums-only one — {@see years}.
     *
     * @return array{books: int, chapters: int, sizeBytes: int, playtimeSeconds: float, authors: int, narrators: int, firstYear: int|null, lastYear: int|null}
     */
    public static function audiobooks(): array
    {
        $chapters = fn () => Track::query()->where('type', TrackType::Audiobook);

        return [
            'books' => Collection::query()->where('type', CollectionType::Audiobook)->count(),
            'chapters' => $chapters()->count(),
            'sizeBytes' => (int) $chapters()->sum('size'),
            'playtimeSeconds' => (float) $chapters()->sum('duration'),
            'authors' => Author::query()->has('tracks')->count(),
            'narrators' => Narrator::query()->has('tracks')->count(),
            ...self::years(CollectionType::Audiobook),
        ];
    }

    /**
     * The oldest and newest year any collection OF ONE KIND carries, as a pair.
     *
     * TWO NULLABLE NUMBERS, NOT A COMPOSED STRING, because the client formats: the cards draw
     * them as one range ("1965–2024"), and a year is the one value on those cards that must NOT
     * be locale-separated — a German "1.965" reads as a quantity. Both are null for a collection
     * whose rows are all untagged (SQL's MIN/MAX skip nulls), and a card then draws nothing at
     * all rather than a dash with blanks either side.
     *
     * ONE ROW, TWO AGGREGATES, ONE PASS — the alternative is two queries for one tile.
     *
     * SCOPED BY TYPE, and that is the whole reason it takes an argument: `collections` holds
     * albums and audiobooks in one table, so a book's year has no business in a music card and
     * an album's none in a book card. Passing the type is what keeps the two cards honest about
     * being about different things.
     *
     * @return array{firstYear: int|null, lastYear: int|null}
     */
    private static function years(CollectionType $type): array
    {
        $years = Collection::query()
            ->where('type', $type)
            ->selectRaw('min(year) as first_year, max(year) as last_year')
            ->first();

        return [
            'firstYear' => $years?->first_year === null ? null : (int) $years->first_year,
            'lastYear' => $years?->last_year === null ? null : (int) $years->last_year,
        ];
    }
}
