<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-confirming the signed-in user's password (`POST /confirm-password`).
 *
 * Presence only. Whether the password is CORRECT is not a validation rule here and
 * deliberately so: the controller answers a wrong one with a hand-built 422 JSON body,
 * because the 2FA composable posts here with fetch() and needs the error keyed on
 * `password` in a shape it can read inline. See ConfirmPasswordController.
 *
 * No `authorize()`: the route is behind `auth`, and a user may always re-confirm their own
 * password — there is no other subject involved.
 */
class ConfirmPasswordRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
