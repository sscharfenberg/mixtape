<?php

namespace App\Http\Requests\Audiobooks;

use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards the book's .zip (`GET /audiobooks/{audiobook}/download`).
 *
 * Same gate as the book PAGE, for the reason the album pair gives: a download offered on a
 * page the reader may read must not answer 403. Being signed in comes from the route group;
 * what is left is the type check, in AuthorizesAudiobook.
 */
class AudiobookDownloadRequest extends FormRequest
{
    use AuthorizesAudiobook;
}
