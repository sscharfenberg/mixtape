<?php

namespace App\Models;

use Database\Factories\PlaylistTrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered entry in a saved playlist. `track_id` is a real FK that is always
 * live (relink-then-cascade, data-model.md → (b) #4), so no denormalised snapshot
 * is needed. `position` is contiguous and renumbered in a txn on reorder.
 */
#[Fillable(['playlist_id', 'track_id', 'position'])]
class PlaylistTrack extends Model
{
    /** @use HasFactory<PlaylistTrackFactory> */
    use HasFactory, HasUuids;

    /** Only `created_at` — entries are appended/reordered, never "updated". */
    const UPDATED_AT = null;

    /**
     * Writing an entry bumps its PLAYLIST's `updated_at`.
     *
     * Without this, a playlist's `updated_at` only ever moves when its name or
     * description is edited — so the listing's "changed" fact would say nothing about
     * the thing a listener actually changes, which is what is IN the playlist. Eloquent
     * touches owners on save AND on delete (Model::delete calls touchOwners before the
     * row goes), so adding, reordering and removing tracks all count.
     *
     * The entry's own `UPDATED_AT` being null is unrelated: this updates the parent's
     * column, not this row's.
     *
     * @var array<int, string>
     */
    protected $touches = ['playlist'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
