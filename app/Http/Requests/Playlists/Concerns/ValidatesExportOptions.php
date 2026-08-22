<?php

namespace App\Http\Requests\Playlists\Concerns;

use App\Services\Playlists\PlaylistExport;
use Illuminate\Validation\Rule;

/**
 * The three choices an .m3u export is made with, shared by the two requests that take them.
 *
 * ONE PLAYLIST AND ALL OF THEM ASK THE SAME THREE QUESTIONS, because all three are answered by
 * the device that will play the file rather than by what is being exported — so the rules, the
 * defaults and the messages belong in one place. Written twice they would drift, and the drift
 * would be a reader whose car preset works from a playlist's own page and not from the listing.
 *
 * A trait rather than a base class, the standing rule here: {@see ExportPlaylistRequest} also
 * needs ownership of the routed playlist, and the collection export has no subject to own.
 */
trait ValidatesExportOptions
{
    /**
     * Fill in what was not asked for, BEFORE the rules see it — so `in:` validates the value
     * that will actually be used rather than an absent one.
     *
     * The prefix's default is config rather than a literal, since it is the one option that
     * depends on where the reader keeps their music. A reader with an export preset never
     * reaches it: the dialog sends their own value.
     *
     * `?prefix=` IS NOT AN ABSENT PREFIX, and telling the two apart is the whole of the care
     * below. `ConvertEmptyStringsToNull` runs before this, so an explicitly emptied field
     * arrives as null WITH ITS KEY PRESENT — which means `input()`'s default never fires for it,
     * and the empty prefix that relative paths depend on would fall through as null. It is put
     * back as ''; only a value that is neither a string nor null (an array from a hand-written
     * query) passes through untouched, for the `string` rule to refuse rather than for a cast to
     * die on.
     */
    protected function prepareForValidation(): void
    {
        $prefix = $this->input('prefix', config('mixtape.playlists.export.path_prefix'));

        $this->merge([
            'format' => $this->input('format', 'simple'),
            'encoding' => $this->input('encoding', 'UTF-8'),
            'prefix' => match (true) {
                is_string($prefix) => trim($prefix),
                $prefix === null => '',
                default => $prefix,
            },
        ]);
    }

    /**
     * `format` and `encoding` are drawn from the service's own constants, so the list cannot
     * drift from what it can actually render.
     *
     * `prefix` is a path typed by a person, and never touched as one by this app — it is only
     * concatenated into the file's text, so it needs a length bound rather than a filesystem
     * check. A LINE BREAK is the one character that turns a prefix into forged content: it
     * would split the .m3u into extra lines the reader never wrote, `#EXTM3U` among them.
     * `not_regex` rather than `doesnt_contain`, which is an ARRAY rule — pointed at a string it
     * fails every value, including the default.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(PlaylistExport::FORMATS)],
            'encoding' => ['required', Rule::in(PlaylistExport::ENCODINGS)],
            'prefix' => ['present', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return __('playlist.export');
    }
}
