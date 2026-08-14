<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one reader left off in one BOOK — the chapter and how far into it.
 *
 * Distinct from `PlayerState`, which is the live queue and holds whatever is playing NOW.
 * This is the memory that outlives it: a reader has three books on the go, puts one down for
 * a fortnight, and expects to come back to chapter 279 rather than to the start.
 *
 * Keyed by the (user, collection) PAIR — the identity is the pair, so there is no surrogate
 * id and no HasUuids. Only `updated_at` is tracked; when a bookmark was FIRST set answers
 * nothing anybody asks.
 */
#[Fillable(['user_id', 'collection_id', 'track_id', 'position_ms'])]
class AudiobookBookmark extends Model
{
    /**
     * Composite keys are not something Eloquent models natively, so nothing here may rely on
     * `find()` or on the key being one column: every read goes through a `where` on both, and
     * the DB's own primary key is what actually enforces one row per reader per book.
     */
    protected $primaryKey = null;

    public $incrementing = false;

    /**
     * Scope a SAVE to this one row, naming both halves of the key.
     *
     * WITHOUT THIS, EVERY WRITE REWRITES THE WHOLE TABLE, and it does so silently. Eloquent
     * builds an update's WHERE from `getKeyName()`, which is null here — and `where(null, '=',
     * null)` does not throw. It routes to `whereNull(null)`, whose `Arr::wrap(null)` is an EMPTY
     * array, so the loop that adds the clause runs zero times and the UPDATE goes out unscoped.
     * Every reader's place in every book is then overwritten on every heartbeat.
     *
     * `getOriginal(...)` rather than the current attribute, because the pair IS the identity: a
     * row whose key was reassigned in memory must still update the row it came from, not the one
     * it now claims to be.
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->scopeToPair($query);
    }

    /** The same pair for a reload (`fresh()` / `refresh()`), unscoped for the same reason. */
    protected function setKeysForSelectQuery($query)
    {
        return $this->scopeToPair($query);
    }

    /**
     * Narrow `$query` to this bookmark's (reader, book) pair.
     *
     * Shared by the two overrides above so the identity is spelled out once — two copies of a
     * key definition is how one of them ends up a column short.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function scopeToPair($query)
    {
        return $query
            ->where('user_id', $this->getOriginal('user_id', $this->getAttribute('user_id')))
            ->where('collection_id', $this->getOriginal('collection_id', $this->getAttribute('collection_id')));
    }

    /** No `created_at` column — the row is upserted, only `updated_at` matters. */
    const CREATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position_ms' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The book this marks a place in.
     *
     * @return BelongsTo<Collection, $this>
     */
    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    /**
     * The chapter the reader was on — `position_ms` is an offset INTO THIS, not into the book.
     *
     * @return BelongsTo<Track, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'track_id');
    }
}
