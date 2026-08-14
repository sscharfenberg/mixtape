<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Enums\ShareSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\ShareCoverRequest;
use App\Models\Share;
use App\Services\Media\CoverService;
use App\Services\Shares\ShareGrant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE SHARED SUBJECT's own artwork (`GET /s/{share}/cover`, route `shares.cover`, NO auth) —
 * the picture in the guest page's hero, as opposed to the per-track thumbnails beside its
 * rows (ShareTrackCoverController).
 *
 * A FOURTH ROUTE THE PLAN DID NOT HAVE (docs/sharing.md sketched three), added because the
 * hero of an ALBUM share is not the first track's cover. CoverService keeps two orders on
 * purpose: a track prefers its own embedded picture, an album prefers the directory's folder
 * image — because rips exist where every file carries a different inline picture, and there
 * "the embedded cover" makes the record's artwork depend on which track happens to sort
 * first. Drawing the hero from a track route would have imported exactly that bug into the
 * one page a listener sees before deciding whether to press play.
 *
 * Nothing to contain here, hence a lighter guard than its per-track sibling: the URL names
 * the share and nothing else, so what it may serve is settled by which row it resolved.
 * ShareCoverRequest checks the clock and that is all there is to check.
 *
 * TWO KINDS HAVE NO COVER FROM THIS ROUTE AT ALL: an ARTIST, because MixTape stores no artist
 * images, and a PLAYLIST, because it is a list of other people's records rather than a record.
 * The page knows — SharePageController sends `coverUrl: null` for both and fans a few of their
 * own sleeves instead, through the per-track route — so this 404s for them, which nothing
 * points an <img> at.
 */
class ShareCoverController extends Controller
{
    /** Injected so the "which image, in which order" policy stays CoverService's, not this file's. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the subject's cached cover as a private, revalidatable image.
     *
     * A DAY, like every cache header in this space: the URL stops working when the link
     * expires, so a month-long copy in a browser would outlive the share it belongs to.
     */
    public function __invoke(ShareCoverRequest $request, Share $share): BinaryFileResponse
    {
        $path = match (ShareGrant::for($share)->subject()) {
            ShareSubject::Song => $this->covers->path($share->track),
            // An AUDIOBOOK joins the album's arm rather than getting one of its own: it is a
            // `collections` row, its Folder.jpg is in `collections.cover_path`, and
            // AudiobookCoverController serves it with this same call. It was missing until
            // 2026-08-14, so a shared book 404'd here — invisibly, because ShareArtwork had
            // the same hole and so never pointed an <img> at it.
            ShareSubject::Album, ShareSubject::Audiobook => $this->covers->albumPath($share->collection),
            // An artist, a playlist (and a subject this app cannot resolve at all) has no
            // single image — see the class note.
            default => null,
        };

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24)
            ->setAutoEtag();
    }
}
