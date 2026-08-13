<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\SearchKind;
use App\Models\Artist;
use App\Models\User;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Artists, matched on their name — the first group, because one artist row leads to everything
 * by them.
 *
 * THIS IS THE GROUP THAT PAYS FOR THE NARROW READING. Searching "black" finds three artists —
 * Black Sabbath, Beast In Black, Godspeed You! Black Emperor — against 1,238 songs once a
 * song may be returned for its artist's name. Three rows one press from everything those
 * artists recorded is a better answer than two hundred of Black Sabbath's tracks, and it is
 * the reason the wide search moved to the hand-off rather than being dropped.
 *
 * NOT narrowed to artists that have tracks, deliberately: an artist can exist as an
 * album-artist only — a compilation owner like "Irish Folk Festival" — and the Artists LISTING
 * lists them, so a search that hid them would disagree with the page it hands off to. Their
 * album count is what says what they are.
 */
final class ArtistKind extends DatabaseKind
{
    public function kind(): SearchKind
    {
        return SearchKind::Artist;
    }

    /**
     * Every artist, plus their DISCOGRAPHY as the row's second line — albums credited to them
     * (`collections.album_artist_id`), which is the same count the Artists listing shows and
     * the same set the artist page's own discography lists. Not "albums holding a track of
     * theirs", which would count every compilation they appear on.
     */
    protected function query(User $reader): Builder
    {
        return Artist::query()
            ->select(['artists.id', 'artists.name'])
            ->withCount('albums');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['artists.name'];
    }

    protected function ranked(): string
    {
        return 'artists.name';
    }

    protected function tieBreak(): string
    {
        return 'artists.id';
    }

    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.artists.show', $row->id, absolute: false),
            count: (int) $row->albums_count,
        );
    }

    protected function seeAll(string $query): ?string
    {
        return route('music.artists', ['search' => $query], absolute: false);
    }
}
