<?php

namespace App\Http\Requests\Audiobooks;

use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards the book's page (`GET /audiobooks/{audiobook}`).
 *
 * Authorization only — a GET carries no fields. The rule, and why it answers 404 rather than
 * 403, is in AuthorizesAudiobook.
 */
class ShowAudiobookRequest extends FormRequest
{
    use AuthorizesAudiobook;
}
