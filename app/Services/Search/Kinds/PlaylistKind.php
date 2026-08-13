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
 * IT IS THE ONLY KIND WITH NO HAND-OFF. The playlists listing is a hand-ordered list of cards,
 * not a DataTable, so there is no `?search=` to link to: the group shows its five and says
 * nothing more. If a household ever holds enough playlists for that to bite, the fix is that
 * listing growing a search — not this dropdown growing a page.
 */
final class PlaylistKind extends DatabaseKind
{
    public function kind(): SearchKind
    {
        return SearchKind::Playlist;
    }

    /**
     * The reader's own playlists and how many tracks each holds.
     *
     * Counted over `playlistTracks` — the pivot rows — rather than over distinct tracks,
     * because that is what the listing and the detail page count: a playlist that deliberately
     * holds one song twice is two entries long.
     */
    protected function query(User $reader): Builder
    {
        return Playlist::query()
            ->where('playlists.user_id', $reader->id)
            ->select(['playlists.id', 'playlists.name'])
            ->withCount('playlistTracks as tracks_count');
    }

    /** @return list<string> */
    protected function matched(): array
    {
        return ['playlists.name', 'playlists.description'];
    }

    protected function ranked(): string
    {
        return 'playlists.name';
    }

    protected function tieBreak(): string
    {
        return 'playlists.id';
    }

    protected function hit(Model $row): SearchHit
    {
        return new SearchHit(
            id: $row->id,
            name: $row->name,
            href: route('playlists.show', $row->id, absolute: false),
            count: (int) $row->tracks_count,
        );
    }

    /** No listing search to hand off to — see the class note. */
    protected function seeAll(string $query): ?string
    {
        return null;
    }
}
