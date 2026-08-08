<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `plays` — one row per listen, written by a "played" beacon the client fires
     * on `ended`/threshold as the queue advances (data-model.md → "how it plugs
     * in"). This is the only unbounded-growth table, so its composite indexes are
     * the ones that actually matter for read latency.
     *
     * `track_id` is `cascade` (relink-then-cascade, like playlist_tracks): a
     * recording keeps its plays as long as any copy of that audio survives, and
     * loses them only when the last copy is gone.
     *
     * Most-played aggregates by `track_id` — each file counts for itself, so the same
     * recording on an album + a compilation + a best-of is three entries (data-model.md,
     * decision #5, re-decided 2026-08-08; it read `content_hash` here until then, which
     * nothing ever implemented). So both most-played grains are answered by the indexes
     * below with no join at all, and `plays` needs no hash column of its own. A SUBJECT's
     * count — an artist's, a genre's, an album's — does join `plays → tracks` and filters on
     * the taxonomy FK, which `tracks` already indexes.
     *
     * COMMENT-ONLY CHANGE to an applied migration: the schema below is untouched, and this
     * file is not re-run. It is edited rather than left lying because a migration docblock is
     * where the next person looks to find out what an index is for.
     */
    public function up(): void
    {
        Schema::create('plays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('track_id')->constrained('tracks')->cascadeOnDelete();
            $table->timestamp('played_at'); // the listen's own timestamp (no created/updated_at)

            $table->index(['user_id', 'played_at']); // a user's history feed
            $table->index('track_id');               // global most-played + relink UPDATE + cascade check
            $table->index(['user_id', 'track_id']);  // per-user most-played
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plays');
    }
};
