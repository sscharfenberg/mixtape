<?php

declare(strict_types=1);

namespace App\Services\Playlists;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WHICH PRESET THE EXPORT MODAL OPENS ON — the one invariant the presets feature has, in the
 * one place that may write it.
 *
 * The rule: a user with any presets at all has EXACTLY ONE default. Three call sites can break
 * that and each of them goes through this class — creating a preset (the first one takes the
 * flag), choosing a different default, and deleting the one that holds it.
 *
 * WHY IT IS WORTH A CLASS. The failure is silent in both directions: with no default the modal
 * falls back to config and the reader's own prefix quietly stops being offered, and with two
 * the modal opens on whichever row the sort returned first — a plausible preset that is not
 * the one they picked. Nothing errors either way, on any page, so the invariant has to be kept
 * rather than noticed.
 *
 * EVERY WRITE IS CLEAR-THEN-SET, INSIDE A TRANSACTION. Postgres checks the partial unique index
 * (`export_presets_one_default_uq`, see the migration) per statement, so setting the new flag
 * before clearing the old one collides with the row being replaced. The order is not a style
 * choice, and reversing it fails on production while passing on sqlite, which has no such
 * index.
 */
final class ExportPresetDefault
{
    /**
     * Make this preset the reader's default, taking the flag off whichever held it.
     *
     * The clear is scoped to the OWNER rather than to "every default", which is what keeps one
     * reader's choice from touching another's — the same scoping every query in this feature
     * carries.
     */
    public static function promote(ExportPreset $preset): void
    {
        DB::transaction(function () use ($preset): void {
            ExportPreset::query()
                ->where('user_id', $preset->user_id)
                ->whereKeyNot($preset->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $preset->update(['is_default' => true]);
        });
    }

    /**
     * Give the flag to somebody if nobody holds it — called after a preset is deleted.
     *
     * THE SUCCESSOR IS THE FIRST IN READING ORDER, which after the holder is gone means simply
     * the alphabetically first: the same preset the list will draw at the top, so the promotion
     * matches what the reader is looking at when the page redraws.
     *
     * A no-op when a default survives (deleting a non-default) and when nothing survives at all
     * (deleting the last preset) — the second is why this cannot be "promote the first one"
     * unconditionally, and why the modal must still cope with an empty list.
     */
    public static function backfill(User $user): void
    {
        $presets = ExportPreset::query()->where('user_id', $user->id);

        if ($presets->clone()->where('is_default', true)->exists()) {
            return;
        }

        $successor = $presets->clone()->inReadingOrder()->first();

        if ($successor !== null) {
            self::promote($successor);
        }
    }
}
