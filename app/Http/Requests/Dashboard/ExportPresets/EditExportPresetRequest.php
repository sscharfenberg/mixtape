<?php

namespace App\Http\Requests\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\Concerns\AuthorizesExportPresetOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening the edit form over an existing preset (`GET /dashboard/export-presets/{preset}/edit`).
 *
 * Ownership and nothing else — the form is rendered, not submitted, so there is no input to
 * validate. It exists as a class rather than as an `abort_unless` in the controller because
 * that is this project's standing rule: a controller says what happens, a request says what is
 * allowed to reach it.
 */
class EditExportPresetRequest extends FormRequest
{
    use AuthorizesExportPresetOwnership;
}
