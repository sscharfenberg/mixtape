<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Http\Controllers\Concerns\SendsTrackAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\ShareStreamRequest;
use App\Models\Share;
use App\Models\Track;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A shared track's audio (`GET /s/{share}/tracks/{track}/stream`, route
 * `shares.tracks.stream`, NO auth) — the `src` the guest's player loads.
 *
 * ITS OWN CONTROLLER RATHER THAN A BRANCH OF SongStreamController, and the reason is one
 * line of that one: the music-only `TrackType` guard. `/music/songs/{song}/stream` is about
 * music and must refuse an audiobook chapter; this route is about whatever the link grants,
 * and an audiobook share streams audiobook tracks. A shared controller would have to take
 * that guard as a parameter, which is a flag deciding what a route means — easier to get
 * wrong once than to keep right forever, and the wrong way round here opens the library.
 *
 * WHAT IT MAY SERVE IS NOT DECIDED HERE. ShareStreamRequest asks ShareGrant, over the same
 * query the guest page was drawn from, so the page and this route cannot come to disagree
 * about which tracks belong to a link.
 *
 * EVERYTHING BELOW THE GUARD IS LITERALLY THE SAME CODE — {@see SendsTrackAudio}, shared with
 * the song and chapter streams: the same `X-Accel-Redirect` hand-off to nginx when
 * `mixtape.stream.internal_prefix` is set, the same direct BinaryFileResponse when it is not,
 * the same `Accept-Ranges` and `206` behaviour that make dragging the timeline work at all
 * (docs/player.md). A guest's playback must not be a second implementation of playback — and a
 * copy that merely started identical is one a later fix to the trait would quietly miss.
 *
 * `setPrivate()` for the same reason as the authenticated route, and if anything a firmer
 * one: a shared proxy holding a track from this URL would be serving it to people who never
 * had the link, and would go on doing so after the share expired.
 */
class ShareStreamController extends Controller
{
    use SendsTrackAudio;

    /**
     * Send the shared track's audio, by whichever route the environment allows.
     *
     * THE BYTES ARE {@see SendsTrackAudio}'S, not this controller's, and that sharing is the
     * point: the readability guard, the nginx hand-off, the `private` caching and the ETag must
     * behave identically for a guest and for a signed-in reader, or a fix to one path silently
     * misses the other. What differs is the guard that admitted the track — a live share
     * containing it, rather than the music-only type check — and the cache lifetime below.
     *
     * A DAY RATHER THAN A MONTH, which is the one number this route changes: a browser told to
     * keep the bytes for thirty days would go on playing a share for weeks after it expired,
     * from a URL the server has begun refusing. A day keeps a reload cheap without outliving
     * the link by much, and the ETag makes the revalidation in between a 304.
     */
    public function __invoke(ShareStreamRequest $request, Share $share, Track $track): BinaryFileResponse|LaravelResponse
    {
        return $this->sendAudio($track, self::SHARED_AUDIO_MAX_AGE);
    }
}
