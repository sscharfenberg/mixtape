<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\PasswordEntropy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Update the authenticated user's password (ported from cantrip.me).
 *
 * Same password rules as registration / reset (App\Actions\Fortify\
 * CreateNewUser, App\Http\Controllers\Auth\NewPasswordController): the
 * zxcvbn entropy gate plus Laravel's default complexity rule.
 *
 * Validation goes through the injected request (not the $input array), same
 * as CreateNewUser: only $request->validate() applies Laravel's
 * Precognition-Validate-Only rule filtering, so the dashboard's per-field
 * live validation checks just the field that changed instead of the whole
 * form (a plain Validator::make(...)->validate() call ignores that header
 * entirely and always validates every rule).
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    /** Inject the request so $request->validate() honours Precognition's per-field validation (see the class docblock). */
    public function __construct(protected Request $request) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        precognitive(fn () => $this->request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default(), new PasswordEntropy],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]));

        $user->forceFill([
            'password' => Hash::make($this->request->input('password')),
        ])->save();
    }
}
