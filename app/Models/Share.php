<?php

namespace App\Models;

use App\Enums\ShareSubject;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
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
 * MINTING AND THE `/s/` GUEST SPACE ARE BOTH BUILT (2026-08-11). What is still designed and
 * not written is the "My shares" dashboard list — so a link can be handed out and played,
 * but revoking one means deleting the row by hand — and the pruning schedule that eventually
 * sweeps dead rows. Both are in docs/sharing.md.
 *
 * @property-read Carbon $valid_until
 */
#[Fillable(['user_id', 'track_id', 'collection_id', 'artist_id', 'playlist_id', 'note', 'valid_until'])]
class Share extends Model
{
    /** @use HasFactory<ShareFactory> */
    use HasFactory, HasUuids, MassPrunable;

    /**
     * How long a link lives, in days.
     *
     * ONE RULE RATHER THAN A PER-LINK CHOICE, and no never-expiring option: a link that
     * never dies is the one that eventually leaks, and a form asking "how long?" is a
     * question about a decision the recipient cannot see. Seven days is long enough for
     * "listen to this" to be opened over a weekend.
     */
    public const LIFETIME_DAYS = 7;

    /**
     * How long a DEAD link is kept before it is swept, in days past `valid_until`.
     *
     * EXPIRED ROWS LINGER ON PURPOSE, which is the whole reason this number is not zero. The
     * owner's list shows them (`/dashboard/shared`), so a link somebody opens on day nine is
     * a row its minter can see and re-send in one press rather than a thing that vanished —
     * and a week is short for "listen to this". Sweeping them the moment they expired would
     * delete exactly the rows most likely to be asked about.
     *
     * THIRTY DAYS is where the two costs cross. Long enough that "where did the link I sent
     * Oma go?" has an answer on screen; short enough that the list stays a list of what a
     * reader is doing rather than an archive of everything they have ever sent. Revoking
     * skips all of this — that is a decision, and it is honoured immediately.
     */
    public const PRUNE_AFTER_DAYS = 30;

    /**
     * The share's public address — the URL handed to whoever it was minted for.
     *
     * Absolute, because it is going into a chat window rather than into an <a href>: the
     * one caller is the mint response, whose whole output is a string a reader copies.
     */
    public function url(): string
    {
        return route('shares.show', $this);
    }

    /**
     * The rows a prune sweeps: dead, and dead for longer than the grace period.
     *
     * MASS-PRUNABLE rather than `Prunable`, so the delete is one statement and no model is
     * ever hydrated. The difference is that per-row `deleting` events do not fire — which is
     * correct here rather than a shortcut, because a share owns nothing outside its row.
     * There is no file to unlink and no cache to clear; the URL stops working because the row
     * it resolved through is gone, which is the same mechanism revoking uses.
     *
     * Driven by `php artisan model:prune --model="App\Models\Share"` on a systemd timer
     * rather than by Laravel's scheduler — see docs/self-hosting/03-production-deploy.md for
     * why this box schedules that way (a home server that sleeps through 04:00 needs
     * `Persistent=true`, and a missed `dailyAt()` is simply lost).
     */
    public function prunable(): Builder
    {
        return static::query()->where('valid_until', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
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
