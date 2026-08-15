<?php

declare(strict_types=1);

namespace App\Services\Search\Kinds;

use App\Enums\SearchKind;
use App\Models\Playlist;
use App\Models\User;
use App\Services\Search\SearchHit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The reader's OWN playlists — the awkward kind, in three ways that are all worth knowing
 * before touching it (docs/search.md → "Playlists are the awkward one").
 *
 * IT IS THE ONLY USER-SCOPED KIND. Everything else in the library belongs to everybody; a
 * playlist belongs to one account, so the same query answers differently per reader. That is
 * what makes the whole response uncacheable (`Cache-Control: private, no-store` on the
 * endpoint) — two people in one household typing the same three letters get different totals —
 * and it is what a test has to pin: a stranger's playlist must never appear, whatever it is
 * called.
 *
 * IT IS THE ONLY KIND MATCHED ON TWO COLUMNS. A playlist carries a blurb the reader wrote, and
 * "the one for the long drive" is a fair thing to search for. It is still RANKED on the name
 * alone, so a playlist found only by its description lands in the "anywhere else" tier rather
 * than pretending its name matched. Both columns needed a `_fold` companion, which is the one
 * migration this feature brought with it.
 *
 * IT HAS NO HAND-OFF, one of two kinds that does not (audiobooks are the other). The playlists
 * listing is a hand-ordered list of cards, not a DataTable, so there is no `?search=` to link
 * to: the group shows its five and says
 * nothing more. If a household ever holds enough playlists for that to bite, the fix is that
 * listing growing a search — not this dropdown growing a page.
 */
final class PlaylistKind extends DatabaseKind
{
    /** The registry key and the group's place in the order — `SearchKind::cases()` is that order. */
    public function kind(): SearchKind
    {
        return SearchKind::Playlist;
    }

    /**
     * The reader's own playlists, with how many tracks each holds and how long it runs.
     *
     * BOTH ARE COUNTED OVER THE PIVOT — the `playlist_tracks` rows — rather than over distinct
     * tracks, and that is right for both: a playlist deliberately holding one song twice is two
     * entries long and takes twice as long to play. Note this is the OPPOSITE of the rule
     * `PlayCounts::forPlaylist` must follow, where a join over the same pivot makes one listen
     * count twice: a LISTEN is an event that happened once, while a track sitting in a
     * list twice really is played twice. Same table, two questions.
     *
     * `withSum` through the `tracks` relation, which is the same idiom the playlists listing
     * uses — one answer to "how long does this run", written once. The relation carries an
     * ORDER BY for the playlist's running order and that is harmless here: `withAggregate`
     * nulls a sub-query's orders before it runs (QueriesRelationships), so the aggregate never
     * sees it. Null when the playlist is empty, so the client draws no pip rather than "0:00".
     */
    protected function query(User $reader): Builder
    {
        return Playlist::query()
            ->where('playlists.user_id', $reader->id)
            ->select(['playlists.id', 'playlists.name'])
            ->withCount('playlistTracks as tracks_count')
            ->withSum('tracks as total_duration', 'duration');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['playlists.name', 'playlists.description'];
    }

    /** Ranked on the NAME alone, though the blurb is matched too: a playlist found only by its
     * description belongs in the "anywhere else" tier rather than at the top. */
    protected function ranked(): string
    {
        return 'playlists.name';
    }

    /** The id, so two playlists of the same name cannot tie and flicker between identical queries. */
    protected function tieBreak(): string
    {
        return 'playlists.id';
    }

    /** One playlist → its page, with how many entries it holds and how long they run. */
    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('playlists.show', $row->id, absolute: false),
            facts: [
                'tracks' => (int) $row->tracks_count,
                'duration' => $row->total_duration === null ? null : (float) $row->total_duration,
            ],
        );
    }

    /** No listing search to hand off to — see the class note. */
    protected function seeAll(string $query): ?string
    {
        return null;
    }
}
