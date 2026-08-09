<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Services\Playlists\PlaylistExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The .m3u download's options (`GET /playlists/{playlist}/export`).
 *
 * THE LEGACY VERSION VALIDATED NOTHING — `$request->get('encoding')` went straight into
 * `mb_convert_encoding`, so any string a caller liked reached mbstring, and `type` decided a
 * branch on a raw request value. Both are `in:` rules here, drawn from the service's own
 * constants so the list cannot drift from what it can actually render.
 *
 * The options arrive as QUERY PARAMS because the download is a plain GET the browser performs
 * itself — see PlaylistExportController for why that beats a fetch-and-blob.
 *
 * `prepareForValidation` supplies the defaults, so a request that names nothing still exports
 * something sensible; the prefix's default is config, not a literal, since it is the one option
 * that depends on where the reader keeps their music.
 */
class ExportPlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;

    /**
     * Fill in what was not asked for, BEFORE the rules see it — so `in:` validates the value
     * that will actually be used rather than an absent one.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'format' => $this->input('format', 'simple'),
            'encoding' => $this->input('encoding', 'UTF-8'),
            'prefix' => trim((string) $this->input('prefix', config('mixtape.playlists.export.path_prefix'))),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(PlaylistExport::FORMATS)],
            'encoding' => ['required', Rule::in(PlaylistExport::ENCODINGS)],
            // A path typed by a person, and never touched as one by this app — it is only
            // concatenated into the file's text, so it needs a length bound rather than a
            // filesystem check. A LINE BREAK is the one character that turns a prefix into
            // forged content: it would split the .m3u into extra lines the reader never wrote,
            // `#EXTM3U` among them. `not_regex` rather than `doesnt_contain`, which is an ARRAY
            // rule — pointed at a string it fails every value, including the default.
            'prefix' => ['present', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return __('playlist.export');
    }
}
