<?php

namespace App\Http\Requests\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\Concerns\ValidatesExportPreset;
use App\Models\ExportPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Creating an export preset (`POST /dashboard/export-presets`).
 *
 * No `authorize()`, and that is a decision rather than an omission: any signed-in reader may
 * describe a device of their own, so the route's `auth` middleware is the whole of the
 * authorization. There is no subject to own yet — ownership only becomes a question once a
 * request names an existing preset ({@see UpdateExportPresetRequest}).
 *
 * Fields, messages and input cleaning are shared with the edit; the CAP below is not, because
 * an edit adds nothing to the count.
 */
class StoreExportPresetRequest extends FormRequest
{
    use ValidatesExportPreset;

    /**
     * How many presets one reader may keep.
     *
     * A bound rather than a considered maximum: presets are devices a person owns, so the real
     * number is three or four, and this is only here to stop a script filling the picker in the
     * export modal — a dropdown is not a list view and does not degrade gracefully into one.
     */
    public const LIMIT = 20;

    /** Nothing to ignore in the unique rule: there is no existing row yet. */
    protected function editedPreset(): ?ExportPreset
    {
        return null;
    }

    /**
     * Refuse the create once the reader is at the cap.
     *
     * VALIDATION, NOT AUTHORIZATION — they are allowed to make presets, they simply have
     * enough. `authorize()` would answer 404 here (the ownership trait's rule), which would
     * read as the page having vanished; a field error names the actual problem, in a place the
     * form already knows how to draw.
     *
     * Reported on `name` because that is the only field the form can point at — the limit is
     * about the row, not about any value in it, and an error with no field renders nowhere.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $held = ExportPreset::query()->where('user_id', $this->user()->id)->count();

            if ($held >= self::LIMIT) {
                $validator->errors()->add('name', __('preset.validation')['name.too_many']);
            }
        });
    }
}
