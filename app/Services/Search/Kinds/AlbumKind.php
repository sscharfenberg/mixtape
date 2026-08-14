<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\CollectionType;
use App\Enums\SearchKind;
use App\Models\Collection;
use App\Models\User;
use App\Services\DataTableService;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Music albums, matched on their own name.
 *
 * `type = album` IS LOAD-BEARING, not tidy: `collections` is the merged albums + audiobooks
 * table, so without it a search for a music album would return audiobooks — the same class of
 * bug the share request's type narrowings exist to prevent. Audiobooks are a registry entry of
 * their own ({@see AudiobookKind}), which is what keeps the two answers apart.
 *
 * THE ALBUM ARTIST IS JOINED FOR THE SECOND LINE ONLY, and is deliberately NOT searched: two
 * albums called "Live" are told apart by who made them, but an album is not an answer to its
 * artist's name — the artist is, in the group above. The join is a LEFT one because a
 * compilation may carry no credit at all.
 */
final class AlbumKind extends DatabaseKind
{
    /** The registry key and the group's place in the order — `SearchKind::cases()` is that order. */
    public function kind(): SearchKind
    {
        return SearchKind::Album;
    }

    /** Albums only: `collections` is the merged table, so the `type` narrowing is what keeps a
     * book out of a record search. Carries the artist and the track count for the second line. */
    protected function query(User $reader): Builder
    {
        return Collection::query()
            ->where('collections.type', CollectionType::Album)
            ->leftJoin('artists', 'collections.album_artist_id', '=', 'artists.id')
            ->select(['collections.id', 'collections.name', 'artists.name as artist_name'])
            // How many tracks it holds — the album's own, so a compilation counts what is on it.
            ->withCount('tracks');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['collections.name'];
    }

    /** Ranked and sorted A→Z on the album's own name — not the artist's, which is the group above. */
    protected function ranked(): string
    {
        return 'collections.name';
    }

    /** The id, so two albums of the same name cannot tie and flicker between identical queries. */
    protected function tieBreak(): string
    {
        return 'collections.id';
    }

    /** One album → its page, with the artist who made it and how many tracks it holds. */
    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.albums.show', $row->id, absolute: false),
            facts: [
                'artist' => $row->artist_name,
                'songs' => (int) $row->tracks_count,
            ],
        );
    }

    /**
     * The listing, narrowed to the same reading THIS group used.
     *
     * `searchIn=name` is what makes the hand-off honest: the group counts rows matching their own
     * name, and the listing's default search is wider — so without the mode "show all 70" landed on
     * a table of 2,000+, and the two numbers contradict each other in front of the reader. See
     * DataTableService::SEARCH_IN_NAME for the whole argument, and
     * note that the listing keeps the mode while the reader sorts, pages and re-types, with its
     * toolbar offering the way back out to the wide search.
     */
    protected function seeAll(string $query): ?string
    {
        return route('music.albums', [
            'search' => $query,
            'searchIn' => DataTableService::SEARCH_IN_NAME,
        ], absolute: false);
    }
}
