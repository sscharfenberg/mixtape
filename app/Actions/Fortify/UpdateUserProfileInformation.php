<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Features;

/**
 * Update the authenticated user's name/email (ported from cantrip.me).
 *
 * When the email address changes on a verified user, verification is revoked
 * and a fresh verification notification is dispatched — the account can't
 * skip re-confirming a new address just because the old one was verified.
 *
 * Validation goes through the injected request (not the $input array), same
 * as App\Actions\Fortify\CreateNewUser: only $request->validate() applies
 * Laravel's Precognition-Validate-Only rule filtering, so the dashboard's
 * per-field live validation checks just the field that changed instead of
 * the whole form (a plain Validator::make(...)->validate() call ignores that
 * header entirely and always validates every rule).
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(protected Request $request) {}

    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        precognitive(fn () => $this->request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:80',
                Rule::unique('users')->ignore($user->id),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ]));

        $name = $this->request->input('name');
        $email = $this->request->input('email');

        if ($email !== $user->email &&
            $user instanceof MustVerifyEmail &&
            Features::enabled(Features::emailVerification())) {
            $this->updateVerifiedUser($user, $name, $email);
        } else {
            $user->forceFill([
                'name' => $name,
                'email' => $email,
            ])->save();
        }
    }

    /**
     * Update a previously verified user's profile information.
     *
     * Clears the email verification timestamp and sends a fresh
     * verification notification so the user must re-verify the new address.
     */
    protected function updateVerifiedUser(User $user, string $name, string $email): void
    {
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
