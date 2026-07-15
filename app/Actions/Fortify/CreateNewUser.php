<?php

namespace App\Actions\Fortify;

use App\Models\Invite;
use App\Models\User;
use App\Rules\ValidInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Creates a newly registered user — but only in exchange for a valid one-time
 * invite. Bound to Fortify's registration via Fortify::createUsersUsing() in
 * FortifyServiceProvider, so Fortify's RegisteredUserController runs this on
 * POST /register.
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Store e-mails lower-cased so uniqueness (and future lookups) are
        // case-insensitive without a DB collation — `name` gets that via its ICU
        // collation; e-mail is simpler to just normalise. Normalise BEFORE
        // validating so `unique:users` checks the value we will actually store.
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = Str::lower($input['email']);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:80', 'unique:users'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'code' => ['required', 'string', new ValidInvite],
        ])->validate();

        return DB::transaction(function () use ($input) {
            // Re-read the invite under a row lock and consume it in the same
            // transaction as the user insert. ValidInvite above is only a
            // friendly pre-check; between it and here the row could have been
            // redeemed by someone racing the same link, so this is authoritative.
            $invite = Invite::query()
                ->where('token', Invite::hashCode($input['code']))
                ->where('valid_until', '>', now())
                ->lockForUpdate()
                ->first();

            if ($invite === null) {
                throw ValidationException::withMessages([
                    'code' => 'Dieser Einladungslink ist ungültig oder abgelaufen.',
                ]);
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            // single-use: spend the invite so the link can never be reused.
            $invite->delete();

            return $user;
        });
    }
}
