<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ExportPresets\SetDefaultExportPresetRequest;
use App\Models\ExportPreset;
use App\Services\Playlists\ExportPresetDefault;
use Illuminate\Http\RedirectResponse;

/**
 * Make one preset the one the export modal opens on
 * (`PATCH /dashboard/export-presets/{preset}/default`).
 *
 * A ROUTE OF ITS OWN rather than a checkbox on the edit form, and the reason is what the
 * default is FOR: it is the answer to "which of these am I usually exporting to", which
 * changes when a reader changes machine — not when they edit a preset. One press from the row
 * beats opening a form, changing a box and saving. `PATCH /shares/{share}/renew` is the same
 * shape for the same reason.
 *
 * A PATCH because it changes one property of a row that goes on existing, and because the same
 * press twice leaves the same state — a double-click cannot produce two defaults.
 *
 * THE INVARIANT IS THE SERVICE'S, not this method's: at most one default per user, cleared
 * before it is set. See ExportPresetDefault for why the order is load-bearing on production.
 *
 * `back()` rather than a redirect to the list: the reader is already looking at the list and
 * expects the marker to move, not the page to change.
 */
class ExportPresetDefaultController extends Controller
{
    /** Promote this preset, demoting whichever held the flag. */
    public function __invoke(SetDefaultExportPresetRequest $request, ExportPreset $preset): RedirectResponse
    {
        ExportPresetDefault::promote($preset);

        return back()->with([
            'message' => __('flash.preset.default', ['name' => $preset->name]),
            'type' => 'success',
        ]);
    }
}
