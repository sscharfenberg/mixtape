<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordEntropy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Setting a new password from a reset link (`POST /reset-password`), for guests.
 *
 * These rules back the live strength meter as much as the submit: `PasswordRule::default()`
 * carries the app's length/complexity policy and PasswordEntropy is the zxcvbn score, so
 * the field says the same thing on blur as it will on submit.
 *
 * The `token` is validated only as PRESENT here. Its authenticity is Fortify's password
 * broker's business, which re-checks it before the reset closure runs — so a forged token
 * fails there, with the broker's own status mapped to a field error in the controller.
 *
 * No `authorize()`: the route is in the `guest` group; the token is what stands in for
 * identity, and the broker is what verifies it.
 */
class NewPasswordRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
            'password' => ['required', 'string', PasswordRule::default(), new PasswordEntropy],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }
}
