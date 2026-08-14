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
 * Audiobooks, matched on their own name.
 *
 * The other half of AlbumKind's `type` narrowing, and the entry both that class and
 * SearchKind said would arrive the day this area existed. `collections` is the merged table,
 * so the two kinds are the same query with opposite filters — which is exactly why they are
 * two registry entries rather than one kind with a branch inside it.
 *
 * ITS AUTHORS ARE NOT SEARCHED, deliberately, and it is the same rule the whole engine turns
 * on: a row matches its OWN name (docs/search.md). Searching a book by its author would make
 * "Lovecraft" return six books, an author group and a shelf's worth of chapters, when what a
 * reader typing a person's name wants is the person. Authors have no group of their own here
 * because they have no page of their own — they are an accordion section on the area page —
 * so the honest answer for a name is the BOOK, found by its title.
 *
 * NO `seeAll`, like a playlist: there is no audiobook LISTING at `?search=` to hand off to.
 * The area page's three tabs are a browse, not a search result, and pointing "show all" at a
 * page that would ignore the query is worse than not offering it.
 */
final class AudiobookKind extends DatabaseKind
{
    /** The registry key and the group's place in the order — `SearchKind::cases()` is that order. */
    public function kind(): SearchKind
    {
        return SearchKind::Audiobook;
    }

    /** Audiobooks only: the same `type` narrowing as albums, read the other way round. */
    protected function query(User $reader): Builder
    {
        return Collection::query()
            ->where('collections.type', CollectionType::Audiobook)
            ->select(['collections.id', 'collections.name', 'collections.year'])
            // How many chapters it holds — the book's own second-line fact, and the one that
            // says most about what kind of thing it is (a 33-chapter anthology against a
            // 673-chapter novel).
            ->withCount('tracks');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['collections.name'];
    }

    /** Ranked and sorted A→Z on the book's own title — never its author, who has no page to reach. */
    protected function ranked(): string
    {
        return 'collections.name';
    }

    /** The id, so two books of the same title cannot tie and flicker between identical queries. */
    protected function tieBreak(): string
    {
        return 'collections.id';
    }

    /** One book → its page, with the chapter count that says most about what kind of book it is. */
    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('audiobooks.show', $row->id, absolute: false),
            facts: [
                // A NUMBER, never a phrase: the second line is composed on the client, because
                // "33 Kapitel" built here would be German on a page read in English
                // (docs/search.md).
                'tracks' => (int) $row->tracks_count,
            ],
        );
    }

    /** Null: the audiobooks entry page is a cover grid rather than a DataTable, so there is no
     * `?search=` to hand off to. Playlists answer null for the same shape of reason. */
    protected function seeAll(string $query): ?string
    {
        return null;
    }
}
