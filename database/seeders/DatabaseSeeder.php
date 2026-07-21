<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Seeding a test user and a fake library only makes sense off production —
     * the live collection is built from the media on disk via the artisan
     * library scan, never from factories. Bail out on production so an
     * accidental `db:seed` / `migrate --seed` there is a no-op.
     */
    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->warn('Skipping DatabaseSeeder: seeding is disabled in production.');

            return;
        }

        $this->call([
            UserSeeder::class,
            LibrarySeeder::class, // depends on UserSeeder (attaches listening data to the seeded user)
        ]);
    }
}
