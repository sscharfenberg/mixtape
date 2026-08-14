<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\SearchKind;
use App\Enums\TrackType;
use App\Models\Track;
use App\Models\User;
use App\Services\DataTableService;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Songs, matched on their title alone — the group where the narrow rule shows its numbers.
 *
 * Searching "black" is 77 songs by title. Let a song also answer for its artist, album or genre
 * and it is 1,238, a tenth of the library, almost all of it "Black Metal" AS A GENRE pulling in
 * every track filed under it. In a grouped dropdown that is not a longer answer, it is a worse
 * one: "Back in Black" would be one row among 1,238. The wide reading is still there, one press
 * away, in the listing this hands off to — `/music/songs?search=` matches title, artist, album
 * and genre, sorted and paginated.
 *
 * `type = music` for the reason the album kind states: `tracks` holds audiobook chapters too,
 * and one answering a music search would link to a page it has no business on.
 *
 * MOST-PLAYED FIRST, LATER. `plays` is an event table with a handful of rows on a young
 * instance, so ordering by it today would order nothing. Once history has volume it is the
 * natural upgrade inside this group — as a subquery over the MATCHED ids (77 for "black"),
 * never over the library.
 */
final class SongKind extends DatabaseKind
{
    public function kind(): SearchKind
    {
        return SearchKind::Song;
    }

    /**
     * Every music track, with its performing artist for the second line — the fact that tells
     * two songs of the same name apart, and the one a reader is most likely scanning for.
     * Joined rather than eager-loaded so it costs no second query per keystroke.
     */
    protected function query(User $reader): Builder
    {
        return Track::query()
            ->where('tracks.type', TrackType::Music)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            // `duration` is the track's own column, so the row's second fact costs no join and
            // no aggregate. Null for the odd file whose tags carried no duration, which the
            // client draws as no pip rather than as 0:00.
            ->select(['tracks.id', 'tracks.name', 'tracks.duration', 'artists.name as artist_name']);
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['tracks.name'];
    }

    protected function ranked(): string
    {
        return 'tracks.name';
    }

    protected function tieBreak(): string
    {
        return 'tracks.id';
    }

    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('music.songs.show', $row->id, absolute: false),
            facts: [
                'artist' => $row->artist_name,
                'duration' => $row->duration === null ? null : (float) $row->duration,
            ],
        );
    }

    /**
     * The listing, narrowed to the same reading THIS group used.
     *
     * `searchIn=name` is what makes the hand-off honest: the group counts rows matching their own
     * name, and the listing's default search is wider — so without the mode "show all 70" lands on
     * a table of 2,000+, and the two numbers contradict each other in front of the reader. See
     * DataTableService::SEARCH_IN_NAME for the whole argument, and
     * note that the listing keeps the mode while the reader sorts, pages and re-types, with its
     * toolbar offering the way back out to the wide search.
     */
    protected function seeAll(string $query): ?string
    {
        return route('music.songs', [
            'search' => $query,
            'searchIn' => DataTableService::SEARCH_IN_NAME,
        ], absolute: false);
    }
}
