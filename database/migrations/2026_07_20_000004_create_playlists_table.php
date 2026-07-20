<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `playlists` — first-class and user-owned (legacy playlists were global,
     * denormalised afterthoughts). A user's own ordering of their playlists lives
     * in `position` (contiguous integers, renumbered in a txn on reorder —
     * data-model.md → open decision #4).
     */
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Ownership cascades: delete a user → delete their playlists (and, in
            // turn, their playlist_tracks). This is true ownership, so `cascade`
            // is correct here (data-model.md → (b) #1).
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0); // user's ordering of their own playlists
            $table->timestamps();

            // "your Rock ≠ my Rock". This composite also serves every "a user's
            // playlists" lookup and the user-delete cascade check, so user_id needs
            // no standalone index (data-model.md → (c)).
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
