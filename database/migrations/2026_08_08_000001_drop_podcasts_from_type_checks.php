<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Narrow the two type CHECKs to the kinds that still exist: podcasts are gone
     * (2026-08-08). A podcast is something you listen to on the service that publishes
     * it rather than a folder of mp3s anybody downloads, so the area was dropped whole
     * — pages, routes, icon, library path and enum cases — rather than left as a
     * scaffold nobody would finish.
     *
     * THE ORIGINAL MIGRATIONS WERE EDITED TOO, so a fresh install never mints a
     * constraint mentioning a type the app has no case for; this exists for the
     * databases already built from the old ones (the live box and the dev site). On a
     * fresh database it finds constraints that already say the right thing and rewrites
     * them to the same text.
     *
     * POSTGRES ONLY, deliberately. Laravel spells `enum()` as a varchar plus a CHECK on
     * both drivers, but sqlite keeps that check INSIDE the table definition — narrowing
     * it means rebuilding a table carrying eight indexes and three foreign keys, and the
     * only sqlite database in this project is the throwaway the E2E suite migrates from
     * scratch on every run. It gets the narrow constraint from the edited original.
     *
     * No rows can be affected: podcasts were never implemented, so nothing ever wrote
     * one of these values.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->recheck('tracks', ['music', 'audiobook']);
        $this->recheck('collections', ['album', 'audiobook']);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->recheck('tracks', ['music', 'audiobook', 'podcast']);
        $this->recheck('collections', ['album', 'audiobook', 'podcast_show']);
    }

    /**
     * Replace a column's allowed-values CHECK with one naming exactly `$values`.
     *
     * `DROP … IF EXISTS` because the constraint's name is Laravel's convention rather
     * than a promise, and a database that never had it must not stop the migration.
     *
     * @param  list<string>  $values
     */
    private function recheck(string $table, array $values): void
    {
        $list = collect($values)->map(fn (string $value) => "'{$value}'")->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$list}))");
    }
};
