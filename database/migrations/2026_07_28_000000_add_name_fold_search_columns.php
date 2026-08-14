<?php

use App\Services\Search\FoldedSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `name_fold` — the accent- and case-folded companion to every searchable
     * `name`, plus the `pg_trgm` GIN indexes that let a leading-wildcard
     * `LIKE '%x%'` use an index instead of reading every row (data-model.md → "Indexes",
     * substring search).
     *
     * Why a stored column instead of folding in SQL: `unaccent()` is Postgres-only
     * and cannot be indexed without an IMMUTABLE wrapper lying about the dictionary,
     * and Postgres refuses LIKE/ILIKE/regex on the nondeterministic
     * `case_insensitive` ICU collation the raw names carry — which is why today's
     * search is a hard 500 on the sqlite test DB and therefore untested. A plain
     * column on the default (deterministic) collation takes the same `like` on both
     * drivers, and the folding rule lives in ONE greppable, unit-tested PHP method.
     */
    private const TABLES = ['tracks', 'collections', 'artists', 'authors', 'narrators', 'genres'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // No ->collation(): the fold column must stay on the database
                // default (deterministic) collation, or Postgres would refuse LIKE
                // on it exactly as it does on the raw `name`.
                // Nullable for the moment between adding it and the backfill below;
                // the HasFoldedName mutator fills it for every row written after.
                $blueprint->string('name_fold', 255)->nullable();
            });

            $this->backfill($table);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Trusted extension since PG13, so the app's own DB user can install it
            // as long as it holds CREATE on the database — no superuser step.
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            foreach (self::TABLES as $table) {
                // Trigram GIN is the only index a leading-wildcard LIKE can use; a
                // B-tree on name_fold would sit unused while Postgres scanned all
                // ~12k tracks per keystroke.
                DB::statement("CREATE INDEX {$table}_name_fold_trgm ON {$table} USING gin (name_fold gin_trgm_ops)");
            }
        }
    }

    public function down(): void
    {
        // Dropping the column takes its trigram index with it. `pg_trgm` itself
        // stays installed on purpose: it is a database-wide facility other work
        // will want, and dropping it would fail anyway if anything else used it.
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('name_fold');
            });
        }
    }

    /**
     * Fill `name_fold` for the rows that already exist. Row-at-a-time through the
     * query builder — NOT the Eloquent models — because a migration must keep
     * working when the models move on, and because the fold is a PHP function that
     * no single UPDATE statement can express. ~14k statements across all six tables
     * on the live collection: seconds, once.
     */
    private function backfill(string $table): void
    {
        DB::table($table)->select('id', 'name')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['name_fold' => FoldedSearch::fold($row->name)]);
            }
        });
    }
};
