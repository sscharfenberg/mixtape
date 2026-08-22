<?php

namespace App\Http\Requests\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\Concerns\AuthorizesExportPresetOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Making a preset the one the export modal opens on
 * (`PATCH /dashboard/export-presets/{preset}/default`).
 *
 * A route of its own rather than a field on the save, so choosing a default is one press from
 * the list — the shape `PATCH /shares/{share}/renew` already established for a row-level state
 * change. Ownership only: the body carries nothing, because the URL is the whole request.
 */
class SetDefaultExportPresetRequest extends FormRequest
{
    use AuthorizesExportPresetOwnership;
}
