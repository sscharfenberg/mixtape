<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the initial account (ported from cantrip.me's UserSeeder).
     *
     * Onboarding is invite-only — open registration is disabled — so there is no
     * self-service flow to create the very first user; this seeds it. The email
     * is pre-verified so the account is immediately usable. Created through the
     * factory rather than a raw insert so it picks up the model's HasUuids (uuid7)
     * id and the `hashed` password cast, matching how the app mints real users.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Ashaltiriak',
            'email' => 'ashaltiriak@mixtape.me',
            'email_verified_at' => now(),
            'password' => Hash::make('passwort'),
        ]);
    }
}
