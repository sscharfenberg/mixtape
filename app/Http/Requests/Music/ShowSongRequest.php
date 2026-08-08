<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards one song's detail page (`GET /music/songs/{song}`).
 *
 * Authorization only — a GET carries no fields. The rule itself, and why it answers 404
 * rather than 403, is in AuthorizesMusicTrack.
 */
class ShowSongRequest extends FormRequest
{
    use AuthorizesMusicTrack;
}
