<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time, expiring registration invite.
 *
 * Onboarding is invite-only: a user can register only by redeeming a valid
 * invite. The plaintext code is never stored — only its sha256 hash (`token`) —
 * so a database dump reveals no usable codes; the plaintext exists solely in the
 * link produced by `php artisan app:invite`. Invites are single-use: they are
 * deleted the moment they are redeemed (App\Actions\Fortify\CreateNewUser).
 */
#[Fillable(['token', 'note', 'valid_until'])]
class Invite extends Model
{
    use HasUuids;

    /**
     * Hash a plaintext invite code into the value stored in `token`.
     *
     * sha256 (not bcrypt) because the code is already high-entropy random, so a
     * fast digest is safe and — unlike a salted password hash — lets us look a
     * code up by its hash in a single indexed equality query.
     */
    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
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
