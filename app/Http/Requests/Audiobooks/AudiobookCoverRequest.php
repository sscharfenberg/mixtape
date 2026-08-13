<?php

namespace App\Http\Requests\Audiobooks;

use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards a book's cover art (`GET /audiobooks/{audiobook}/cover`).
 *
 * Authorization only; the rule is in AuthorizesAudiobook.
 */
class AudiobookCoverRequest extends FormRequest
{
    use AuthorizesAudiobook;
}
