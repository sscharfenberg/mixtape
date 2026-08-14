<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\SearchKind;
use App\Enums\TrackType;
use App\Models\Genre;
use App\Models\User;
use App\Services\Music\DominantGenre;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Genres, matched on their name — LAST in the fixed group order, and that is a judgement about
 * what a reader is doing rather than about how many rows there are.
 *
 * A genre is a shelf. Somebody typing three letters is almost always after a thing — a record,
 * a performer, a title they half remember — and only occasionally after a whole shelf of them.
 * So the shelves go below the things, and the ten genres matching "black" cannot push the three
 * artists off the top of the list.
 *
 * The second line is how many songs carry it, scoped to music like every count in this app: a
 * chapter cannot legally carry a genre (the tracks CHECK forbids it), so the scope is
 * belt-and-braces today and what keeps the number right the day a kind that CAN carry one is
 * added.
 */
final class GenreKind extends DatabaseKind
{
    /** The registry key and the group's place in the order — `SearchKind::cases()` is that order. */
    public function kind(): SearchKind
    {
        return SearchKind::Genre;
    }

    /**
     * Every genre, with how many artists it owns and how many songs carry it.
     *
     * ARTISTS COUNTS THE ONES WHOSE MAIN GENRE THIS IS (DominantGenre), not everyone who ever
     * recorded a song in it — the same rule the Genres listing and the genre's own page use, so a
     * reader meeting the same genre twice is never told two different things. LEFT-joined and
     * COALESCEd, because a genre can hold plenty of songs while being nobody's main one.
     */
    protected function query(User $reader): Builder
    {
        return Genre::query()
            ->select(['genres.id', 'genres.name'])
            ->leftJoinSub(DominantGenre::artistCountsPerGenre(), 'main', 'main.genre_id', '=', 'genres.id')
            ->selectRaw('coalesce(main.artists_count, 0) as artists_count')
            ->withCount(['tracks as songs_count' => fn (Builder $q): Builder => $q->where('tracks.type', TrackType::Music)]);
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['genres.name'];
    }

    /** Ranked and sorted A→Z on the genre's name. */
    protected function ranked(): string
    {
        return 'genres.name';
    }

    /** The id, so two genres of the same name cannot tie and flicker between identical queries. */
    protected function tieBreak(): string
    {
        return 'genres.id';
    }

    /** One genre → its page, with how many artists call it theirs and how many songs it holds. */
    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.genres.show', $row->id, absolute: false),
            facts: [
                'artists' => (int) $row->artists_count,
                'songs' => (int) $row->songs_count,
            ],
        );
    }

    /**
     * No `searchIn=name` on this one, unlike the songs and albums hand-offs: this listing's own
     * search already matches nothing but the name, so the wide and narrow readings are the same
     * query and the mode would be a claim with nothing behind it.
     */
    protected function seeAll(string $query): ?string
    {
        return route('music.genres', ['search' => $query], absolute: false);
    }
}
