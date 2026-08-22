<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Http\Requests\Playlists\Concerns\ValidatesExportOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The .m3u download's options (`GET /playlists/{playlist}/export`).
 *
 * THE LEGACY VERSION VALIDATED NOTHING — `$request->get('encoding')` went straight into
 * `mb_convert_encoding`, so any string a caller liked reached mbstring, and `type` decided a
 * branch on a raw request value. Both are `in:` rules now, drawn from the service's own
 * constants (see the trait, which the collection export shares).
 *
 * The options arrive as QUERY PARAMS because the download is a plain GET the browser performs
 * itself — see PlaylistExportController for why that beats a fetch-and-blob.
 *
 * What is left here is the OWNERSHIP: a playlist that is not the reader's answers 404.
 */
class ExportPlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;
    use ValidatesExportOptions;
}
