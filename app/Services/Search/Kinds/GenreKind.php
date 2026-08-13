<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\SearchKind;
use App\Enums\TrackType;
use App\Models\Genre;
use App\Models\User;
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
    public function kind(): SearchKind
    {
        return SearchKind::Genre;
    }

    protected function query(User $reader): Builder
    {
        return Genre::query()
            ->select(['genres.id', 'genres.name'])
            ->withCount(['tracks as songs_count' => fn (Builder $q): Builder => $q->where('tracks.type', TrackType::Music)]);
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['genres.name'];
    }

    protected function ranked(): string
    {
        return 'genres.name';
    }

    protected function tieBreak(): string
    {
        return 'genres.id';
    }

    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.genres.show', $row->id, absolute: false),
            count: (int) $row->songs_count,
        );
    }

    protected function seeAll(string $query): ?string
    {
        return route('music.genres', ['search' => $query], absolute: false);
    }
}
