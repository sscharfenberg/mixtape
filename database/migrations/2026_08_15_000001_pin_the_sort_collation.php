<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin the collation of every column the app SORTS BY, so alphabetical order stops depending on
 * how somebody happened to run `createdb`.
 *
 * MEASURED, ON THE LIVE CLUSTER: `mixtape_dev` was created `C.UTF-8` and `mixtape_prod`
 * `en_GB.UTF-8`. Only the taxonomy `name` columns pinned a collation of their own
 * (`case_insensitive`); `tracks.name`, `playlists.name` and every `*_fold` column took the
 * DATABASE DEFAULT — so the two boxes sorted the same library differently, and not subtly:
 *
 *   C.UTF-8       A Beast Am I | A Beautiful Song | A Boy Named Sue | A Broken Man…
 *   en_GB.UTF-8   Aaj | Aaskereia | Abandoned | Abandoned | Abandoned (Live)…
 *
 * A space sorts below every letter in byte order and is ignored at the primary level in a
 * locale-aware one, so the whole first page of the Songs listing — which defaults to sorting by
 * name — differs. 9,786 of 12,081 track titles contain a space, so this is the ordinary case
 * rather than an edge one.
 *
 * `en_GB.utf8` because that is what production already reads like: dictionary order, where
 * "Aaj" precedes "A Beast Am I". Note this is NOT what the ICU collations do — `en-GB-x-icu`
 * treats a space as significant and orders those the other way round (word-by-word, the way a
 * card catalogue sorts). Both are defensible; this one is the one the library is already in.
 *
 * NOT the taxonomy `name` columns. Those carry `case_insensitive` for a different job — making
 * the scanner's `firstOrCreate` fold "Rock" and "rock" together — and changing them would
 * change deduplication, not just display order.
 *
 * SQLITE IS LEFT ALONE, and that is a real limitation rather than an oversight: sqlite offers
 * only BINARY, NOCASE and RTRIM, none of which is locale-aware, so the test suite still orders
 * byte-wise whatever this migration does. An ordering assertion that could be decided by a
 * space is therefore still asserting the wrong engine's answer — which is why the ranking
 * fixtures deliberately diverge at a letter (see SearchTest).
 */
return new class extends Migration
{
    /**
     * Columns whose ORDER BY a reader sees, keyed by table.
     *
     * @var array<string, list<string>>
     */
    private const SORTED = [
        'tracks' => ['name', 'name_fold'],
        'playlists' => ['name', 'name_fold', 'description_fold'],
        'artists' => ['name_fold'],
        'authors' => ['name_fold'],
        'collections' => ['name_fold'],
        'genres' => ['name_fold'],
        'narrators' => ['name_fold'],
    ];

    /** The collation production already sorts in. */
    private const COLLATION = 'en_GB.utf8';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertCollationExists();

        foreach (self::SORTED as $table => $columns) {
            foreach ($columns as $column) {
                // A type change is what carries a collation; the type is restated unchanged.
                // Postgres rewrites the column and REBUILDS every index over it, which is the
                // point — a pg_trgm GIN index built under one collation is not valid under
                // another, and leaving it would answer stale rows rather than fail loudly.
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE varchar(255) COLLATE "%s"',
                    $table,
                    $column,
                    self::COLLATION
                ));
            }
        }
    }

    /**
     * Refuse early, with the fix in the message, when the locale is not installed.
     *
     * `en_GB.utf8` is a LIBC collation: it exists only if the operating system has that locale
     * generated, so a fresh host following the self-hosting guide can reach this migration
     * without it. Postgres's own error names neither the cause nor the remedy, and a migration
     * that half-applied would leave some columns pinned and some not — the exact inconsistency
     * this is here to remove.
     */
    private function assertCollationExists(): void
    {
        $exists = DB::selectOne(
            'select 1 as found from pg_collation where collname = ?',
            [self::COLLATION]
        );

        if ($exists !== null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The "%s" collation does not exist in this PostgreSQL cluster. It is a libc locale, '
            .'so the OS has to provide it: enable en_GB.UTF-8 in /etc/locale.gen, run '
            .'`sudo locale-gen`, restart PostgreSQL, then `ALTER SYSTEM` is not needed — the '
            .'collation is picked up automatically. See docs/self-hosting/02-host-setup.md.',
            self::COLLATION
        ));
    }

    /**
     * Hand the columns back to the database default.
     *
     * Which is not necessarily where they started — the default differs per install, and that
     * is the whole reason this migration exists. Down is therefore "stop pinning", not "restore
     * what was there".
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::SORTED as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE varchar(255) COLLATE pg_catalog."default"',
                    $table,
                    $column
                ));
            }
        }
    }
};
