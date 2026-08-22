<?php

namespace App\Http\Requests\Dashboard\ExportPresets\Concerns;

use App\Models\ExportPreset;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This preset is the reader's own", for every request that takes one from the URL.
 *
 * The model arrives already resolved: route-model binding is substituted in middleware, before
 * the controller — and therefore before this request — is constructed, so `$this->route('preset')`
 * is the ExportPreset itself rather than an id.
 *
 * A trait, not a parent class, for the reason {@see ValidatesExportPreset} records: the edit
 * form, the delete and the default-switch need this and no fields, the save needs this AND the
 * fields, and one chain cannot serve both.
 */
trait AuthorizesExportPresetOwnership
{
    /** The routed preset must belong to the caller — a preset describes one person's devices. */
    public function authorize(): bool
    {
        $preset = $this->route('preset');

        return $preset instanceof ExportPreset && $preset->user_id === $this->user()?->id;
    }

    /**
     * 404, not the FormRequest default of 403 — the standing rule for anything user-owned on
     * this instance, which is deliberately reachable from the internet and shared with family
     * and friends. "You may not edit that" confirms the preset EXISTS; a 404 answers the same
     * way whether it never existed or simply isn't theirs.
     */
    protected function failedAuthorization(): never
    {
        abort(Response::HTTP_NOT_FOUND);
    }

    /** The preset this request is about, for the rules and the controller. */
    protected function editedPreset(): ?ExportPreset
    {
        $preset = $this->route('preset');

        return $preset instanceof ExportPreset ? $preset : null;
    }
}
