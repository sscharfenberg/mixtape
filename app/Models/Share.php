<?php

namespace App\Models;

use App\Enums\ShareSubject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link that lets someone WITHOUT AN ACCOUNT listen to one subject (docs/sharing.md) —
 * the headline use case of an instance that is deliberately reachable from the internet.
 *
 * THE ROW IS THE CAPABILITY, and its primary key is the secret: `GET /s/{id}` finds it,
 * and the holder may play what it names until `valid_until` passes. Unlike `Invite`, the id
 * is stored unhashed — a share is re-copied from the owner's list weeks after minting, and
 * a digest cannot be re-displayed.
 *
 * WHAT EXISTS TODAY IS MINTING ONLY (2026-08-11). The `/s/` guest space, the "My shares"
 * dashboard list and the pruning schedule are designed in docs/sharing.md and not built, so
 * a minted link is a real, revocable row that nothing yet serves. `url()` names the address
 * that space will answer at, in one place, so building it moves one string rather than
 * hunting for concatenations.
 *
 * @property-read Carbon $valid_until
 */
#[Fillable(['user_id', 'track_id', 'collection_id', 'artist_id', 'playlist_id', 'note', 'valid_until'])]
class Share extends Model
{
    use HasUuids;

    /**
     * How long a link lives, in days.
     *
     * ONE RULE RATHER THAN A PER-LINK CHOICE, and no never-expiring option: a link that
     * never dies is the one that eventually leaks, and a form asking "how long?" is a
     * question about a decision the recipient cannot see. Seven days is long enough for
     * "listen to this" to be opened over a weekend.
     */
    public const LIFETIME_DAYS = 7;

    /** The share's public address — the URL handed to whoever it was minted for. */
    public function url(): string
    {
        return url('/s/'.$this->getKey());
    }

    /** Whether this link still works, which is only ever a question about the clock. */
    public function isLive(): bool
    {
        return $this->valid_until->isFuture();
    }

    /**
     * Narrow to the links that still work.
     *
     * Expired rows are deliberately left in the table rather than deleted on read — the
     * owner should see a dead link in their list and re-mint in one click, which is what
     * pruning them lazily buys (docs/sharing.md → Pruning).
     *
     * @param  Builder<Share>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('valid_until', '>', now());
    }

    /**
     * Narrow to the shares OF one subject — the same three-way mapping the mint route uses
     * to decide which FK to fill, read back the other way round.
     *
     * @param  Builder<Share>  $query
     */
    public function scopeOfSubject(Builder $query, ShareSubject $subject, string $id): void
    {
        $query->where($subject->foreignKey(), $id);
    }

    /** Who minted it. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The shared song, when this is a song share. @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** The shared album (or, one day, audiobook). @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** The shared artist. @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * The shared playlist. Nothing mints one yet — the column exists so the table's CHECK
     * was written once (see the migration) — but the relation is here so the "My shares"
     * list does not have to grow a special case when it does.
     *
     * @return BelongsTo<Playlist, $this>
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime',
        ];
    }
}
