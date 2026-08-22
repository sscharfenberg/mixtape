<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\ValidatesExportOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The options behind "export all playlists" (`GET /playlists/export`), which answers with a
 * .zip holding one .m3u per playlist.
 *
 * NO `authorize()`, and that is a decision rather than an omission: there is no subject in the
 * URL to own. The archive is built from a query scoped to the caller, so what a reader may
 * export is decided by what the query returns rather than by a check that could be forgotten —
 * the same shape the playlists listing itself takes, and for the same reason its docblock gives.
 *
 * The three options are the single export's, through the shared trait: one playlist and all of
 * them ask the same three questions.
 */
class ExportPlaylistsRequest extends FormRequest
{
    use ValidatesExportOptions;
}
