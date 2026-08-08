<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicAlbum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards an album's cover art (`GET /music/albums/{album}/cover`).
 *
 * Authorization only — a GET carries no fields. The rule itself, and why it answers 404
 * rather than 403, is in AuthorizesMusicAlbum.
 */
class AlbumCoverRequest extends FormRequest
{
    use AuthorizesMusicAlbum;
}
