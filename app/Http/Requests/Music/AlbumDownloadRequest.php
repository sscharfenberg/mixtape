<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicAlbum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards the album's .zip (`GET /music/albums/{album}/download`).
 *
 * Same gate as the album PAGE, for the reason its song counterpart gives: a download
 * offered on a page the reader may read must not answer 403. Being signed in comes from
 * the route group; what is left is the type check.
 *
 * Authorization only; a GET carries no fields. The rule, and why it answers 404 rather
 * than 403, is in AuthorizesMusicAlbum.
 */
class AlbumDownloadRequest extends FormRequest
{
    use AuthorizesMusicAlbum;
}
