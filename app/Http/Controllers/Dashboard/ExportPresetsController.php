<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ExportPreset;
use App\Services\Playlists\ExportPresetRows;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * THE READER'S OWN EXPORT PRESETS (`GET /dashboard/export-presets`, route `dashboard.presets`,
 * behind auth) — the devices they export playlists for, and the only place a preset is made,
 * renamed, made default or deleted.
 *
 * A DASHBOARD SUBPAGE RATHER THAN A DASHBOARD SECTION, for the reason SharesController records
 * about the share list: a list of unknown length in the middle of a settings page pushes
 * everything below it out of reach. What sits on the dashboard is a heading and a link.
 *
 * REACHED FROM THREE PLACES, and their gating differs on purpose. The dashboard section is
 * always drawn — the dashboard is where settings live and it is exhaustive, so it is also
 * where a reader who has never heard of presets meets them. The user menu's entry is drawn
 * only for a reader who HAS one (`hasExportPresets`), because that menu is a shortcut list
 * rather than a table of contents. And the export modal links here, which is where the need is
 * actually felt — standing in the dialog retyping a path.
 *
 * ONE LIST, IN THE ORDER THE MODAL'S PICKER USES ({@see ExportPreset::scopeInReadingOrder}) —
 * the default first, then by name. The two surfaces share the scope rather than each writing
 * an `orderBy`, so the preset this page marks is the preset that dialog opens on.
 */
class ExportPresetsController extends Controller
{
    /** The reader's presets, already in reading order. Empty is a real and common state. */
    public function __invoke(Request $request): Response
    {
        $presets = ExportPreset::query()
            ->where('user_id', $request->user()->id)
            ->inReadingOrder()
            ->get();

        return Inertia::render('Dashboard/ExportPresets/ExportPresetsPage', [
            'presets' => ExportPresetRows::for($presets),
        ]);
    }
}
