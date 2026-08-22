<?php

namespace App\Http\Requests\Dashboard\ExportPresets\Concerns;

use App\Models\ExportPreset;
use App\Services\Playlists\PlaylistExport;
use Illuminate\Validation\Rule;

/**
 * The preset form's fields, shared by the create and the edit request.
 *
 * A trait rather than a common parent, for the reason ValidatesPlaylistMetadata records: the
 * two requests share along TWO axes that do not nest — the create shares these FIELDS with the
 * edit, while the edit shares its OWNERSHIP check with the three requests that have no fields
 * at all ({@see AuthorizesExportPresetOwnership}). One inheritance chain cannot express both.
 */
trait ValidatesExportPreset
{
    /**
     * Clean the input BEFORE the rules see it, so `unique` and `max` measure what will
     * actually be stored — the ordering ValidatesPlaylistMetadata explains at length.
     *
     * `path_prefix` IS THE ONE THAT BITES. An empty prefix is a real, ordinary value — it is
     * what the car preset holds, where the playlist sits beside the music and the paths are
     * relative — but `ConvertEmptyStringsToNull` runs in the global middleware stack, BEFORE
     * this, so the empty field arrives as `null` rather than as `''`. Left alone, the `string`
     * rule below would reject exactly the preset the feature exists for. Putting it back here
     * rather than in the controller is what makes `max:255` measure the value the database gets.
     *
     * NOTHING IS COERCED, AND THAT IS THE OTHER HALF. Cleaning runs before any rule, so it sees
     * whatever was posted — including an ARRAY, which neither `$this->string()` (it throws) nor
     * `(string)` (an "Array to string conversion" warning, which Laravel's error handler
     * rethrows) survives. Either way a hand-written body answers 500 rather than 422, on a route
     * whose whole job is to refuse bad input politely. So a non-string is passed through
     * untouched and left to the `string` rule, which reports it as the field error it is.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $prefix = $this->input('path_prefix');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'path_prefix' => match (true) {
                is_string($prefix) => trim($prefix),
                // The empty field, as ConvertEmptyStringsToNull left it — and an absent one,
                // which must behave identically since the two arrive indistinguishable.
                $prefix === null => '',
                default => $prefix,
            },
        ]);
    }

    /**
     * `name` is unique PER OWNER — the same composite the migration enforces. On an edit the
     * row being saved is ignored, or re-saving without renaming would report the name as taken
     * by the very preset wearing it.
     *
     * `format` and `encoding` are drawn from the SERVICE's own constants rather than spelled
     * out here, so a preset can only ever hold a shape {@see PlaylistExport} has a branch for
     * — the same source ExportPlaylistRequest validates the per-export choice against.
     *
     * `path_prefix` repeats the export request's rule, and the interesting half is
     * `not_regex`: a line break in the prefix would split the .m3u into lines the reader never
     * wrote — `#EXTM3U` among them — since the prefix is concatenated into the file's text.
     * `present` rather than `required`, because '' is a value here and `required` rejects it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unique = Rule::unique('export_presets', 'name')->where('user_id', $this->user()->id);
        $editing = $this->editedPreset();

        return [
            'name' => [
                'required',
                'string',
                'max:60',
                $editing === null ? $unique : $unique->ignore($editing->id),
            ],
            'format' => ['required', Rule::in(PlaylistExport::FORMATS)],
            'encoding' => ['required', Rule::in(PlaylistExport::ENCODINGS)],
            'path_prefix' => ['present', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }

    /**
     * Messages passed INLINE rather than through validation.php's `custom` block, for the
     * reason the playlist form records: that block is keyed by attribute name alone, and its
     * `name` entry belongs to the USERNAME — every auth form in the app posts a `name` and
     * means the login id. Left alone, a duplicate preset name would answer "this username is
     * already taken", which renders, reads as plausible, and is nonsense.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return __('preset.validation');
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('preset.attributes');
    }

    /**
     * The preset being saved, or null when creating one.
     *
     * ABSTRACT rather than defaulted to null, because the update request also uses
     * {@see AuthorizesExportPresetOwnership}, which declares this method too — two traits
     * providing one name is a fatal collision PHP makes you resolve with `insteadof`.
     * Requiring it instead means the update satisfies this from the ownership trait and the
     * create answers null explicitly.
     */
    abstract protected function editedPreset(): ?ExportPreset;
}
