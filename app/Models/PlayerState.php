<?php

namespace App\Models;

use Database\Factories\PlayerStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The server-persisted play queue for a logged-in user — the sync target for the
 * client `usePlayerQueue` composable so the queue and position resume on any
 * device (data-model.md → "the play queue"). Read/written wholesale, so `queue`
 * is a single JSON blob (ordered track ids + current_index + position_ms), not a
 * normalised table.
 *
 * Keyed by `user_id` (1:1 with the user), so no generated uuid of its own — hence
 * no HasUuids. Only `updated_at` is tracked.
 */
#[Fillable(['user_id', 'queue'])]
class PlayerState extends Model
{
    /** @use HasFactory<PlayerStateFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** No `created_at` column — the row is upserted, only `updated_at` matters. */
    const CREATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['queue' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
