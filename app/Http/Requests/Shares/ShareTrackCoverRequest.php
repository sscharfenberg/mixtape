<?php

namespace App\Http\Requests\Shares;

use App\Http\Requests\Shares\Concerns\AuthorizesShareTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards a shared track's cover art (`GET /s/{share}/tracks/{track}/cover`) — the thumbnail
 * beside a row in the guest page's list and in the play queue.
 *
 * The SAME guard as the audio beside it, deliberately: artwork is extracted from the audio
 * file, so a cover route that admitted a track the stream refuses would be handing out a
 * piece of a file that is not shared.
 */
class ShareTrackCoverRequest extends FormRequest
{
    use AuthorizesShareTrack;
}
