<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchKind;
use App\Models\User;
use App\Services\Search\Contracts\SearchableKind;
use App\Services\Search\Kinds\AlbumKind;
use App\Services\Search\Kinds\ArtistKind;
use App\Services\Search\Kinds\AudiobookKind;
use App\Services\Search\Kinds\GenreKind;
use App\Services\Search\Kinds\PlaylistKind;
use App\Services\Search\Kinds\SongKind;

/**
 * The cross-kind search: one question, one group per kind that can answer it
 * (docs/search.md).
 *
 * THE REGISTRY IS THE POINT of this class. Each kind knows its own table, scope, ranking
 * column and link; this only decides which of them are asked and in what order. Adding
 * audiobooks the day that area exists is a class beside the five in `Kinds/`, one enum case,
 * and one line below — not an edit to how matching, counting or ordering work.
 *
 * THE ORDER IS THE ENUM'S, NOT THE CALLER'S. `?kinds=` narrows the answer and cannot reorder
 * it, because this walks `SearchKind::cases()` and skips what was not asked for. A reader
 * scanning a dropdown is aiming at a shape they have learnt; one that reshuffles per query is
 * one they cannot learn.
 *
 * EMPTY GROUPS ARE DROPPED, which is the whole of the "no cross-kind ranking" argument in
 * practice: for `"karma police"` the artist, album and playlist groups have nothing, so the
 * songs group is the first thing on screen without anything having been scored against
 * anything else.
 */
class LibrarySearch
{
    /**
     * The shortest query that is answered at all.
     *
     * THREE IS ABOUT RESULT QUALITY, NOT SPEED, and the measurement says so the other way
     * round from the obvious guess: a two-character `%bl%` scan over 12k tracks came back in
     * 5.0 ms. It is cheap and useless — it matches half the library — and a trigram index
     * cannot help a pattern shorter than a trigram, so the one thing a shorter floor would buy
     * is a page of noise that also loses the index.
     */
    public const MIN_LENGTH = 3;

    /**
     * How many rows each group carries.
     *
     * Five is what fits an overlay without scrolling on a phone, and the group still reports
     * its real total — so "and 72 more" is a link rather than a silence.
     */
    public const PER_KIND = 5;

    /**
     * Search every requested kind and shape the response's `groups`.
     *
     * Already-validated input: the endpoint's FormRequest enforces the length floor and that
     * every requested kind is a real one, so nothing here re-checks either.
     *
     * @param  list<SearchKind>  $kinds  the chip filter; an empty list means every kind
     * @return list<array<string, mixed>>
     */
    public function search(string $query, array $kinds, User $reader): array
    {
        $wanted = $kinds === [] ? SearchKind::cases() : $kinds;
        $registry = $this->registry();

        $groups = [];

        foreach (SearchKind::cases() as $kind) {
            if (! in_array($kind, $wanted, true)) {
                continue;
            }

            $group = $registry[$kind->value]->group($query, $reader, self::PER_KIND);

            if ($group->total > 0) {
                $groups[] = $group->toArray();
            }
        }

        return $groups;
    }

    /**
     * One entry per kind — the registry the class docblock is about.
     *
     * Built per call rather than held as state: the kinds are stateless query builders, a
     * search request asks for at most five of them, and a fresh map keeps this service safe to
     * resolve as a singleton later without anything having to be reset.
     *
     * @return array<string, SearchableKind>
     */
    private function registry(): array
    {
        return [
            SearchKind::Artist->value => new ArtistKind,
            SearchKind::Album->value => new AlbumKind,
            SearchKind::Playlist->value => new PlaylistKind,
            SearchKind::Song->value => new SongKind,
            SearchKind::Genre->value => new GenreKind,
            SearchKind::Audiobook->value => new AudiobookKind,
        ];
    }
}
