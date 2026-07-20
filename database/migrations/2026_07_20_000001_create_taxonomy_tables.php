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
     * `narrators` (data-model.md → (a), "keep the two taxonomy trees separate").
     * All four are identical in shape (a uuid PK + a unique name), so they live in
     * one migration.
     *
     * Two v1 bugs are fixed here (data-model.md → (b) #2):
     *  - Every `name` is now UNIQUE (legacy left albums/genres/audiobooks unindexed
     *    and deduped purely in PHP `firstOrCreate`).
     *  - On Postgres each `name` is pinned to the `case_insensitive` ICU collation
     *    minted by the users migration (`und-u-ks-level2`, strength 2 = ignore case,
     *    keep accents). That collation is Unicode-aware, so Chinese / CJK / any
     *    script sorts and dedupes correctly — it is the v2 equivalent of the legacy
     *    MySQL `utf8mb4_unicode_ci`. Pinning it at the column level also makes the
     *    scanner's `firstOrCreate` case-insensitive transparently ("Rock" == "rock").
     *
     * The sqlite connection used by the test suite doesn't understand the ICU DDL,
     * so the columns keep their default (already case-folding) collation there.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $collation = $pgsql ? 'case_insensitive' : null;

        // Music performer + album-artist tree, and audiobook author/narrator tree.
        // 255 chars is generous headroom for long, multi-byte (CJK) names.
        foreach (['artists', 'authors', 'narrators', 'genres'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($collation) {
                $table->uuid('id')->primary();
                $table->string('name', 255)->collation($collation)->unique();
                // No timestamps: taxonomy rows carry no lifecycle of their own —
                // they are minted/pruned by the scanner (data-model.md → (b) #5).
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
