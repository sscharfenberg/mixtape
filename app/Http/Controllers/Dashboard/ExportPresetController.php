<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ExportPresets\EditExportPresetRequest;
use App\Http\Requests\Dashboard\ExportPresets\StoreExportPresetRequest;
use App\Http\Requests\Dashboard\ExportPresets\UpdateExportPresetRequest;
use App\Models\ExportPreset;
use App\Services\Playlists\ExportPresetDefault;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ONE EXPORT PRESET — created at `GET /dashboard/export-presets/create` + `POST
 * /dashboard/export-presets`, edited at `GET /dashboard/export-presets/{preset}/edit` + `PUT
 * /dashboard/export-presets/{preset}`.
 *
 * FOUR ACTIONS ON ONE CONTROLLER, and one page behind both pairs, for the reason
 * PlaylistMetadataController records: create and edit differ in almost nothing — the same four
 * fields, the same rules, the same messages — and split across two controllers they drift, the
 * drift being invisible until a reader hits it.
 *
 * NOTHING HERE VALIDATES OR AUTHORIZES. Both live in the request classes
 * (App\Http\Requests\Dashboard\ExportPresets): fields in ValidatesExportPreset, ownership in
 * AuthorizesExportPresetOwnership, and Precognition handled by the framework's middleware — a
 * FormRequest filters its own rules and short-circuits with a 204 on a validate-only request.
 *
 * `is_default` IS NOT A FIELD ON THIS FORM. Which preset the export modal opens on is one
 * press from the list ({@see ExportPresetDefaultController}), so a save cannot move it by
 * accident — with one exception, below, that is the opposite of an accident.
 */
class ExportPresetController extends Controller
{
    /** Render the "new preset" form. No preset to seed it with, which is what tells the page it is creating. */
    public function create(): Response
    {
        return Inertia::render('Dashboard/ExportPresets/Preset/ExportPresetPage', [
            'preset' => null,
            // The prefix a reader with no presets starts from — the same config value the
            // export modal falls back to, so their first preset begins where the dialog they
            // came from already was rather than at an empty field.
            'fallbackPrefix' => (string) config('mixtape.playlists.export.path_prefix'),
        ]);
    }

    /** Create the preset, then land the reader back on their list. */
    public function store(StoreExportPresetRequest $request): RedirectResponse
    {
        try {
            $preset = $request->user()->exportPresets()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            // The `unique` rule and the INSERT are not one atomic step, so two submits in
            // flight together (a double-click, a retried request) can both pass validation and
            // only one can land. Answer the loser with the same field error it would have got
            // a moment earlier, rather than a 500. PlaylistMetadataController does the same.
            throw $this->nameTaken();
        }

        // THE FIRST PRESET BECOMES THE DEFAULT, because a reader holding one preset has no
        // choice to make and should not have to make it anyway: without this, their first
        // preset would sit in the list while the export modal went on offering the config
        // prefix. Later ones do not steal the flag — `backfill` is a no-op once somebody holds
        // it, so that is a deliberate press.
        //
        // ASKED AS "DOES ANYBODY HOLD IT?" RATHER THAN "IS THIS THE FIRST?", which is the same
        // answer in the ordinary case and a different one when two creates are in flight
        // together — a double submit that outran the button's own `:disabled`, or two tabs.
        // Both would insert, both would then count two, and a count check would promote
        // NEITHER: the reader ends up with presets and no default, which is one of the two
        // silent failures ExportPresetDefault exists to prevent, and nothing would ever repair
        // it. This is idempotent, so it also heals such a state on the next create.
        ExportPresetDefault::backfill($request->user());

        return $this->done($request, 'flash.preset.created', $preset->name);
    }

    /**
     * Render the edit form over an existing preset.
     *
     * The preset goes over as a prop rather than being fetched by the page: the page has no way
     * to ask, and the request has already loaded the row to check who owns it.
     */
    public function edit(EditExportPresetRequest $request, ExportPreset $preset): Response
    {
        return Inertia::render('Dashboard/ExportPresets/Preset/ExportPresetPage', [
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'format' => $preset->format,
                'encoding' => $preset->encoding,
                'pathPrefix' => $preset->path_prefix,
            ],
            // Unused while editing — the form is seeded from the preset — but sent all the same
            // so the page's props have one shape in both directions.
            'fallbackPrefix' => (string) config('mixtape.playlists.export.path_prefix'),
        ]);
    }

    /** Save the edited preset, then land the reader back on their list. */
    public function update(UpdateExportPresetRequest $request, ExportPreset $preset): RedirectResponse
    {
        try {
            // The fields are already trimmed and the prefix already normalised back from null,
            // so what `validated()` hands over is exactly what should be stored. `is_default`
            // is untouched: a rename is not a change of default.
            $preset->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }

        return $this->done($request, 'flash.preset.updated', $preset->name);
    }

    /**
     * The field error a lost unique race gets, so it reads the same as the rule's own.
     *
     * A ValidationException rather than `back()->withErrors()`: it IS a validation failure, and
     * only the exception renders as a 422 JSON body where the caller asked for one.
     */
    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => __('preset.validation')['name.unique']]);
    }

    /** Flash the outcome and send the reader back to their list of presets. */
    private function done(Request $request, string $key, string $name): RedirectResponse
    {
        $request->session()->flash('message', __($key, ['name' => $name]));
        $request->session()->flash('type', 'success');

        return redirect()->route('dashboard.presets');
    }
}
