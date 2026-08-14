<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Concerns\SendsTrackAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Music\SongStreamRequest;
use App\Models\Track;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One song's audio bytes (`GET /music/songs/{song}/stream`, route `music.songs.stream`,
 * behind auth) — the `src` of the player's <audio> element.
 *
 * Its own controller because it answers with a file rather than an Inertia page, and it
 * carries the music-only `TrackType` guard through SongStreamRequest: the `tracks` table also
 * holds audiobook chapters, and this route is about music.
 *
 * HOW the bytes are sent — the `X-Accel-Redirect` hand-off, the direct path, Range, the
 * private caching — is {@see SendsTrackAudio}, shared with the audiobook chapter stream. The
 * two routes differ only in which guard admits the track.
 */
class SongStreamController extends Controller
{
    use SendsTrackAudio;

    /**
     * Stream one song's audio. The guard is the request's; the bytes are {@see SendsTrackAudio}'s,
     * shared with the audiobook chapter stream.
     */
    public function __invoke(SongStreamRequest $request, Track $song): BinaryFileResponse|LaravelResponse
    {
        return $this->sendAudio($song);
    }
}
