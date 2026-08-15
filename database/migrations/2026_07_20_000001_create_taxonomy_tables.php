<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The taxonomy (lookup) tables: the two contributor trees kept deliberately
     * separate under option B — music `artists` + `genres`, audiobook `authors` +
     * `narrators` (data-model.md → "One tracks table, one collections table").
     * All four are identical in shape (a uuid PK + a unique name), so they live in
     * one migration.
     *
     * Two things are enforced in the DATABASE rather than in PHP (data-model.md →
     * "Foreign keys"):
     *  - Every `name` is UNIQUE. Dedup by `firstOrCreate` alone leaves nothing stopping
     *    two rows for one name if a scan races or a rule changes.
     *  - On Postgres each `name` is pinned to the `case_insensitive` ICU collation
     *    minted by the users migration (`und-u-ks-level2`, strength 2 = ignore case,
     *    keep accents). That collation is Unicode-aware, so Chinese / CJK / any
     *    script sorts and dedupes correctly. Pinning it at the column level also makes
     *    the scanner's `firstOrCreate` case-insensitive transparently ("Rock" == "rock").
     *
     * The sqlite connection used by the test suite doesn't understand the ICU DDL, so it gets
     * `nocase` — sqlite's own case-insensitive collation, which folds ASCII only.
     *
     * NAMING A COLLATION THERE IS NOT OPTIONAL. Sqlite's default is `BINARY`, which is
     * case-SENSITIVE — so left unset, the suite does the exact OPPOSITE of production for two
     * names differing only in case, and neither behaviour looks wrong on its own. Postgres finds
     * the existing row (and unless the scanner rewrites its spelling, a case-only rename then
     * does nothing at all); sqlite creates a second row and prunes the first, so the name looks
     * right and the id silently changes. No test can catch that, because it would be asserting
     * the wrong engine's answer. With `nocase` both dedupe case-insensitively; Postgres
     * additionally does it for every script rather than for ASCII alone, which is a difference
     * the fixtures never reach.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $collation = $pgsql ? 'case_insensitive' : 'nocase';

        // Music performer + album-artist tree, and audiobook author/narrator tree.
        // 255 chars is generous headroom for long, multi-byte (CJK) names.
        foreach (['artists', 'authors', 'narrators', 'genres'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($collation) {
                $table->uuid('id')->primary();
                $table->string('name', 255)->collation($collation)->unique();
                // No timestamps: taxonomy rows carry no lifecycle of their own —
                // they are minted/pruned by the scanner (data-model.md → "Foreign keys").
            });
        }
    }

    public function down(): void
    {
        // Reverse of the create order; nothing references these yet at down()-time
        // because the tables that do (collections, tracks) drop first.
        Schema::dropIfExists('genres');
        Schema::dropIfExists('narrators');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('artists');
    }
};
