<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\CollectionType;
use App\Enums\SearchKind;
use App\Models\Collection;
use App\Models\User;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Music albums, matched on their own name.
 *
 * `type = album` IS LOAD-BEARING, not tidy: `collections` is the merged albums + audiobooks
 * table, so without it a search for a music album would return audiobooks — a kind that has no
 * page to link to yet, which is the same class of bug the share request's type narrowings exist
 * to prevent. Audiobooks become a registry entry of their own when that area exists.
 *
 * THE ALBUM ARTIST IS JOINED FOR THE SECOND LINE ONLY, and is deliberately NOT searched: two
 * albums called "Live" are told apart by who made them, but an album is not an answer to its
 * artist's name — the artist is, in the group above. The join is a LEFT one because a
 * compilation may carry no credit at all.
 */
final class AlbumKind extends DatabaseKind
{
    public function kind(): SearchKind
    {
        return SearchKind::Album;
    }

    protected function query(User $reader): Builder
    {
        return Collection::query()
            ->where('collections.type', CollectionType::Album)
            ->leftJoin('artists', 'collections.album_artist_id', '=', 'artists.id')
            ->select(['collections.id', 'collections.name', 'artists.name as artist_name']);
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['collections.name'];
    }

    protected function ranked(): string
    {
        return 'collections.name';
    }

    protected function tieBreak(): string
    {
        return 'collections.id';
    }

    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.albums.show', $row->id, absolute: false),
            text: $row->artist_name,
        );
    }

    protected function seeAll(string $query): ?string
    {
        return route('music.albums', ['search' => $query], absolute: false);
    }
}
