<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ExportPresets\DestroyExportPresetRequest;
use App\Models\ExportPreset;
use App\Services\Playlists\ExportPresetDefault;
use Illuminate\Http\RedirectResponse;

/**
 * Delete one export preset (`DELETE /dashboard/export-presets/{preset}`).
 *
 * INVOKABLE AND ITS OWN CLASS rather than a `destroy` on ExportPresetController, which owns
 * create/edit/store/update — those four are one form saving one set of fields, and deleting is
 * not an edit of them. The same split PlaylistDeleteController makes.
 *
 * DELETING THE DEFAULT PASSES THE FLAG ON rather than leaving the reader with none: a user
 * holding presets and no default is a user whose export modal has quietly gone back to
 * offering the config prefix, with nothing on any page to say why. The successor is the
 * alphabetically first survivor, which is the row the list will then draw at the top — so the
 * promotion matches what the reader is looking at when the page redraws.
 *
 * `back()` rather than a redirect: the reader is on the list and expects one row fewer, which
 * is also the only announcement the act gets beyond the flash — a deleted preset leaves
 * nothing on screen to point at.
 *
 * NOTHING ELSE HAS TO HAPPEN. A preset is read at export time and referenced by nothing:
 * exported files hold the values, not the row, so deleting one cannot reach back into a
 * playlist anybody has already downloaded.
 */
class ExportPresetDeleteController extends Controller
{
    /** The name is read BEFORE the delete, because the flash names it and the model is gone by then. */
    public function __invoke(DestroyExportPresetRequest $request, ExportPreset $preset): RedirectResponse
    {
        $name = $preset->name;

        $preset->delete();

        ExportPresetDefault::backfill($request->user());

        return back()->with([
            'message' => __('flash.preset.deleted', ['name' => $name]),
            'type' => 'success',
            'duration' => 3000,
        ]);
    }
}
