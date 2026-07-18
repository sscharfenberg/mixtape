<?php

namespace App\Rules;

use App\Models\Invite;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a submitted invite code matches an outstanding, unexpired
 * invite. This is the friendly, form-level check that produces a field error;
 * the authoritative check-and-consume happens under a row lock in
 * App\Actions\Fortify\CreateNewUser, so two people can't race the same link.
 */
class ValidInvite implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ok = is_string($value) && $value !== '' && Invite::query()
            ->where('token', Invite::hashCode($value))
            ->where('valid_until', '>', now())
            ->exists();

        if (! $ok) {
            $fail(__('rules.invite_invalid'));
        }
    }
}
