<?php

namespace App\Models;

use Database\Factories\PlayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listen, written by the client's "played" beacon on `ended`/threshold. The
 * event's own time is `played_at` (there are no created/updated timestamps).
 *
 * Every count over these rows groups by `track_id` — each file counts for itself, so the
 * same recording across an album + compilation + best-of is three entries (data-model.md →
 * "Listen history", which argues it against the `content_hash` grain). App\Services\Player\PlayCounts
 * is the one place that reads them, for a single track and for a whole artist / genre / album.
 */
#[Fillable(['user_id', 'track_id', 'played_at'])]
class Play extends Model
{
    /** @use HasFactory<PlayFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['played_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
