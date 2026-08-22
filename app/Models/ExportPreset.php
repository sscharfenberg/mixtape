<?php

namespace App\Models;

use Database\Factories\ExportPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named bundle of the three .m3u export options, owned by a user — "MacBook", "Auto",
 * "Handy" (see the migration for why the three travel together).
 *
 * IT DESCRIBES A DEVICE, not a playlist and not this server: which .m3u flavour that device
 * understands, which encoding it renders without mojibake, and where the music sits from its
 * point of view. So it hangs off the USER and is picked per export, rather than being stored
 * on anything being exported.
 *
 * `is_default` is which preset the export modal opens on, and at most one per user carries it
 * — a partial unique index says so on production, {@see ExportPresetDefault} keeps it true
 * everywhere. There is always exactly one while the user has any preset at all: the first one
 * created takes the flag, and deleting the holder passes it on.
 */
#[Fillable(['name', 'format', 'encoding', 'path_prefix', 'is_default'])]
class ExportPreset extends Model
{
    /** @use HasFactory<ExportPresetFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** The owner. A preset is theirs alone — every route that names one checks it. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reader's presets in the order every list of them uses: the default first, then by
     * name.
     *
     * A scope rather than the order being retyped, because the two readers of it must agree —
     * the presets page and the export modal's picker. Written twice they would drift, and the
     * drift reads as the modal opening on a different preset from the one the page marks.
     *
     * The default leads because it is the answer to the question both surfaces ask first
     * ("which one am I about to use?"); the rest sort alphabetically, which is stable as
     * presets are added and needs no position column to maintain.
     *
     * @param  Builder<ExportPreset>  $query
     */
    public function scopeInReadingOrder(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderBy('name');
    }
}
