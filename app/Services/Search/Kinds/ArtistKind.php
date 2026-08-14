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
    /** The registry key and the group's place in the order — `SearchKind::cases()` is that order. */
    public function kind(): SearchKind
    {
        return SearchKind::Artist;
    }

    /**
     * Every artist, with the two facts their row shows: how many albums, and how long their
     * tracks run.
     *
     * `albums` is their DISCOGRAPHY — albums credited to them (`collections.album_artist_id`),
     * which is the same count the Artists listing shows and the same set the artist page's own
     * discography lists. Not "albums holding a track of theirs", which would count every
     * compilation they appear on.
     *
     * The runtime is aliased away from `tracks_sum_duration` on purpose: an aggregate landing on
     * an attribute that HAS a cast picks that cast up, which is the trap MusicController's
     * artists() documents. It is null rather than 0 for an artist who performs nothing — a
     * credited-only compilation owner — and the client then draws no pip at all instead of
     * "0:00".
     */
    protected function query(User $reader): Builder
    {
        return Artist::query()
            ->select(['artists.id', 'artists.name'])
            ->withCount('albums')
            ->withSum('tracks as total_duration', 'duration');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['artists.name'];
    }

    /** Ranked and sorted A→Z on the artist's name, which is the only thing an artist is found by. */
    protected function ranked(): string
    {
        return 'artists.name';
    }

    /** The id, so two artists of the same name cannot tie and flicker between identical queries. */
    protected function tieBreak(): string
    {
        return 'artists.id';
    }

    /** One artist → their page, with an album count and a total runtime (null where they perform on none). */
    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.artists.show', $row->id, absolute: false),
            facts: [
                'albums' => (int) $row->albums_count,
                'duration' => $row->total_duration === null ? null : (float) $row->total_duration,
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
        return route('music.artists', ['search' => $query], absolute: false);
    }
}
