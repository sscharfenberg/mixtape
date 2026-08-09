<?php

namespace App\Http\Controllers\Playlists;

use App\Enums\PlaylistSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\AddTracksToPlaylistRequest;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\Playlists\PlaylistAdditions;
use Illuminate\Http\RedirectResponse;

/**
 * Adding tracks to a playlist (`POST /playlists/{playlist}/tracks`, route
 * `playlists.tracks.store`, behind auth) — what the "add to playlist" block in a detail page's
 * hero writes, and what the play queue's menu writes for everything it holds.
 *
 * A POST, unlike the two ordering routes beside it, because every call creates rows: this is an
 * append, not a replacement, so it is not idempotent and must not pretend to be. What makes a
 * double submit harmless instead is that the service filters against what the playlist already
 * holds — press save twice and the second press adds nothing and says so.
 *
 * Nothing here validates or authorizes: AddTracksToPlaylistRequest carries both, and answers
 * 404 rather than 403 for a stranger's playlist, since this box is deliberately reachable from
 * the internet and "you may not add to that" would confirm the playlist exists.
 *
 * IT ANSWERS `back()` WITH A FLASH, rather than JSON. The page the reader is on has props that
 * this write invalidates — `addablePlaylists`, which is precisely the list of playlists the
 * select offers — so the round trip that reports the outcome is also the one that refreshes
 * the offer. The toast is the flash bridge every other mutation here uses (ToastContainer).
 */
class PlaylistTracksController extends Controller
{
    /**
     * Append the requested tracks and say what actually landed.
     *
     * The two body shapes are the request's; all this decides is which of them was sent.
     * `subject` wins when a caller sends both — nothing in this app does, and picking one is
     * better than a rule for a case that cannot arise.
     */
    public function __invoke(AddTracksToPlaylistRequest $request, Playlist $playlist): RedirectResponse
    {
        $data = $request->validated();

        $added = PlaylistAdditions::append($playlist, isset($data['subject'])
            ? PlaylistAdditions::subjectTrackIds(PlaylistSubject::from($data['subject']), $data['id'])
            : array_values($data['tracks'] ?? []));

        return back()->with($this->outcome($added, $playlist));
    }

    /**
     * The flash the toast reads — three messages, because the three outcomes are genuinely
     * different things to be told.
     *
     * ONE TRACK IS NAMED, and the rest are counted. Naming it is the whole confirmation when a
     * reader adds a song from its own page ("‹title› was added to ‹playlist›"); naming twelve
     * would be a paragraph, and they already know what they pressed. The name is fetched only
     * in that one case, so the common bulk path costs no extra query.
     *
     * NOTHING ADDED IS NOT A FAILURE, so it is an `info` rather than an error: the playlist
     * already holds all of it, which is very often exactly what the reader wanted to know.
     *
     * @param  list<string>  $added  the ids that landed, in written order
     * @return array<string, string|int>
     */
    private function outcome(array $added, Playlist $playlist): array
    {
        $message = match (true) {
            $added === [] => __('flash.playlist.tracks_already', ['playlist' => $playlist->name]),
            count($added) === 1 => __('flash.playlist.track_added', [
                'name' => Track::query()->whereKey($added[0])->value('name') ?? '',
                'playlist' => $playlist->name,
            ]),
            default => __('flash.playlist.tracks_added', [
                'count' => count($added),
                'playlist' => $playlist->name,
            ]),
        };

        return [
            'message' => $message,
            'type' => $added === [] ? 'info' : 'success',
            'duration' => 3000,
        ];
    }
}
