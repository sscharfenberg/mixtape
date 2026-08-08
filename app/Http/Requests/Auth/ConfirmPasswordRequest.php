<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Dashboard\DeleteAccountRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-confirming the signed-in user's password (`POST /confirm-password`).
 *
 * `current_password` rather than a comparison of our own, for the reason
 * {@see DeleteAccountRequest} sets out at length: the manual
 * check next door existed to work around `shouldRenderJsonWhen` not matching this route, and
 * that stopped being true once `wantsJson()` was added to it (a5e6659). useTwoFactorAuth
 * posts here with `Accept: application/json` and reads `errors.password[0]`, which is exactly
 * the shape a ValidationException renders.
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
            'password' => ['required', 'current_password'],
        ];
    }

    /**
     * Both failures keep `auth.password`, the key the hand-built 422 used, so this changes no
     * user-visible text — only where the check lives.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => __('auth.password'),
            'password.current_password' => __('auth.password'),
        ];
    }
}
