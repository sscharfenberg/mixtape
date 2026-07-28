<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\Media\CoverService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One song's cover art (`GET /music/songs/{song}/cover`, route
 * `music.songs.cover`, behind auth) — the <img> source of the song page's hero.
 *
 * Its own controller rather than a branch of SongController because it answers
 * with an image, not an Inertia page: separate response type, separate caching,
 * and the page can point an <img> at it without a second Inertia round-trip.
 *
 * CoverService does the work (extract once, then serve from a cached JPEG); this
 * only guards the route and sets the caching. A song with no cover from either
 * source 404s, which the page never triggers — SongController sends `coverUrl:
 * null` in that case and the hero renders its placeholder instead.
 */
class SongCoverController extends Controller
{
    /** Injected so the extraction/caching policy stays in one testable place. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the cached cover as a private, long-lived, revalidatable image.
     *
     * `private` matters: this app is internet-facing behind auth, so a cover must
     * never be cached by a shared proxy where an unauthenticated visitor could
     * pick it up. The long max-age is safe because the URL is keyed by the track
     * id, which is a hash of the audio — different bytes, different URL.
     *
     * The same music-only type check SongController makes: the tracks table also
     * holds audiobook chapters, and this route is about music.
     */
    public function __invoke(Request $request, Track $song): BinaryFileResponse
    {
        abort_unless($song->type === TrackType::Music, Response::HTTP_NOT_FOUND);

        $path = $this->covers->path($song);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24 * 30)
            ->setAutoEtag();
    }
}
