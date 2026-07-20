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
 * Most-played is aggregated by `tracks.content_hash`, not `track_id`, so the same
 * recording across an album + compilation + best-of counts once (open decision
 * #5) — a `plays → tracks` join + `GROUP BY t.content_hash`.
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
