<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `player_states` — the server-persisted play queue, one JSON row per user
     * (data-model.md → "the play queue"). The LIVE queue is a client composable
     * (`usePlayerQueue`) driving background auto-advance; this row is its sync
     * target so the queue and position resume on any device (hydrated via Inertia
     * shared props; anonymous listeners fall back to localStorage).
     *
     * Deliberately NOT a normalised `queue_items` table: unlike a saved playlist
     * (relational, queried, shared), the queue is private to one player and
     * read/written wholesale — load it whole, save it whole. So it's a single
     * `jsonb` blob (an ordered [track_id, …] + current_index + position_ms).
     *
     * `user_id` is the primary key (1:1 with the user) and cascades on delete.
     */
    public function up(): void
    {
        Schema::create('player_states', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->primary('user_id');
            $table->jsonb('queue'); // jsonb on Postgres, text on the sqlite test connection
            $table->timestamp('updated_at')->nullable(); // no created_at (model: const CREATED_AT = null)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_states');
    }
};
