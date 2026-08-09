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
     *
     * LIBRARYSEEDER IS OFF, and commented rather than deleted because it is not
     * wrong, only outgrown: it exists for developing WITHOUT an mp3 collection,
     * and every dev box now has one (`app:update` scans it). Its tracks are
     * factory rows pointing at paths nothing ever wrote, so with a real
     * collection present they are dead weight that cannot be played — and a
     * `migrate:fresh --seed` to reset the account would bury the scanned library
     * under them. Uncomment it on a machine with no media to point at.
     */
    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->warn('Skipping DatabaseSeeder: seeding is disabled in production.');

            return;
        }

        $this->call([
            UserSeeder::class,
            // LibrarySeeder::class, // depends on UserSeeder (attaches listening data to the seeded user)
        ]);
    }
}
