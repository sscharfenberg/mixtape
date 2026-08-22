<?php

namespace App\Http\Requests\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\Concerns\AuthorizesExportPresetOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a preset (`DELETE /dashboard/export-presets/{preset}`).
 *
 * Ownership only, answering 404 for anyone else's — the same posture as revoking a share.
 * Nothing is validated: the row named in the URL is the whole request.
 */
class DestroyExportPresetRequest extends FormRequest
{
    use AuthorizesExportPresetOwnership;
}
