<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards the audio itself (`GET /music/songs/{song}/stream`).
 *
 * Authorization only — a GET carries no fields. The rule itself, and why it answers 404
 * rather than 403, is in AuthorizesMusicTrack.
 */
class SongStreamRequest extends FormRequest
{
    use AuthorizesMusicTrack;
}
