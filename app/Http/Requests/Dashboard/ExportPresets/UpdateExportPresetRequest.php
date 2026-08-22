<?php

namespace App\Http\Requests\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\Concerns\AuthorizesExportPresetOwnership;
use App\Http\Requests\Dashboard\ExportPresets\Concerns\ValidatesExportPreset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving an existing preset (`PUT /dashboard/export-presets/{preset}`).
 *
 * Where the two traits meet: the ownership rule that guards the form, and the field rules that
 * guard the create. `AuthorizesExportPresetOwnership::editedPreset()` is what satisfies the
 * abstract requirement `ValidatesExportPreset` declares, and so what tells the shared `unique`
 * rule to ignore the row being saved. Trait order is irrelevant — the requirement being
 * abstract is precisely what removes the collision that would otherwise need `insteadof`.
 *
 * AUTHORIZATION RUNS BEFORE VALIDATION (ValidatesWhenResolvedTrait::validateResolved), so a
 * stranger gets the 404 without the rules ever executing — which is what stops the validate-only
 * Precognition path being used to tell an existing preset from a missing one.
 *
 * `is_default` is deliberately not among the fields: which preset the modal opens on is its own
 * one-press route, so a save cannot quietly move it (Dashboard\ExportPresetDefaultController).
 */
class UpdateExportPresetRequest extends FormRequest
{
    use AuthorizesExportPresetOwnership;
    use ValidatesExportPreset;
}
