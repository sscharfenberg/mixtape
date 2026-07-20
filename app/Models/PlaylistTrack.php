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
