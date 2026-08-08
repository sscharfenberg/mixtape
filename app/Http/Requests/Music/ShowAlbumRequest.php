<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicAlbum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards one album's detail page (`GET /music/albums/{album}`).
 *
 * Authorization only — a GET carries no fields. The rule itself, and why it answers 404
 * rather than 403, is in AuthorizesMusicAlbum.
 */
class ShowAlbumRequest extends FormRequest
{
    use AuthorizesMusicAlbum;
}
