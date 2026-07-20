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
     * Most-played aggregates by `tracks.content_hash`, not `track_id` — the same
     * recording on an album + a compilation + a best-of counts as ONE song
     * (open decision #5). That is a `plays → tracks` join + `GROUP BY
     * t.content_hash`; the FK indexes here serve the join/filter, so `plays` needs
     * no content_hash of its own.
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
