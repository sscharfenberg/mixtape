<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `playlist_tracks` — the ordered entries of a saved playlist (renamed from
     * legacy `playlist_entries`). It finally holds a REAL `track_id` FK, only
     * possible because v2 ids are stable across scans (data-model.md → (b) #4).
     *
     * `track_id` is `cascade`, and is ALWAYS live: on a file deletion the scanner
     * runs relink-then-cascade — it repoints the entry to a surviving clone (same
     * content_hash) before hard-deleting the track, and only cascades the entry
     * away when no copy of that audio remains. So there are no nulls, no dead
     * entries, and no denormalised snapshot — title/artist come from the join.
     *
     * No `user_id`: ownership rides `playlist_id → playlists → users`.
     * `position` is contiguous, renumbered in a txn on reorder (kept deliberately
     * non-unique to allow transient mid-txn dups — data-model.md → open decision #4).
     */
    public function up(): void
    {
        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('track_id')->constrained('tracks')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->nullable(); // no updated_at (model: const UPDATED_AT = null)

            $table->index(['playlist_id', 'position']); // ordered render (also covers playlist_id)
            $table->index('track_id');                  // reverse lookup + relink UPDATE + cascade check
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_tracks');
    }
};
