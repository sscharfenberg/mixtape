<?php

namespace App\Actions\Fortify;

use App\Models\Invite;
use App\Models\User;
use App\Rules\PasswordEntropy;
use App\Rules\ValidInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Creates a newly registered user — but only in exchange for a valid one-time
 * invite, and only for a strong-enough password. Bound to Fortify's registration
 * via Fortify::createUsersUsing() in FortifyServiceProvider, so Fortify's
 * RegisteredUserController runs this on POST /register.
 *
 * Validation goes through the injected request (not the $input array) so Inertia
 * Precognition can live-validate a single field on the register form without
 * creating a user (see the precognitive() wrapper below).
 */
class CreateNewUser implements CreatesNewUsers
{
    public function __construct(protected Request $request) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Normalise the e-mail on the request so precognition, validation and
        // storage all see the lower-cased value (name gets case-insensitivity
        // from its ICU collation; e-mail is simpler to just lower-case).
        if (is_string($this->request->input('email'))) {
            $this->request->merge(['email' => Str::lower($this->request->input('email'))]);
        }

        // precognitive(): on a precognitive request the helper validates only the
        // requested field(s) and short-circuits (no user created); on a real
        // submit it validates everything, then execution falls through to create.
        precognitive(fn () => $this->request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80', 'unique:users'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::default(), new PasswordEntropy],
            'password_confirmation' => ['required', 'string', 'same:password'],
            'code' => ['required', 'string', new ValidInvite],
        ]));

        return DB::transaction(function () {
            // Re-check + consume the invite under a row lock (race-safe): between
            // the ValidInvite check above and here the row could have been
            // redeemed by someone racing the same link, so this is authoritative.
            $invite = Invite::query()
                ->where('token', Invite::hashCode($this->request->input('code')))
                ->where('valid_until', '>', now())
                ->lockForUpdate()
                ->first();

            if ($invite === null) {
                throw ValidationException::withMessages([
                    'code' => 'Dieser Einladungslink ist ungültig oder abgelaufen.',
                ]);
            }

            $user = User::create([
                'name' => $this->request->input('name'),
                'email' => $this->request->input('email'),
                'password' => Hash::make($this->request->input('password')),
            ]);

            // single-use: spend the invite so the link can never be reused.
            $invite->delete();

            return $user;
        });
    }
}
