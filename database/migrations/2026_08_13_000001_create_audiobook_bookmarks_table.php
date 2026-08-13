<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `audiobook_bookmarks` — where each reader left off in each BOOK.
     *
     * WHY THIS IS NOT `player_states`, which already stores a queue and a position: that row
     * is the LIVE player, one per user, and it holds whatever is playing now. A book is the
     * one thing you put down for a fortnight and come back to, and you do that with three of
     * them at once. Losing your place in "The Stand" because you spent an evening on an
     * anthology is exactly the failure the audiobook area exists to prevent — the owner's
     * words: knowing you are at chapter 279 and not skipping through half a book to find it.
     *
     * So: one row per (reader, book). `player_states` keeps doing its job unchanged, and this
     * is the memory that outlives the queue.
     *
     * COMPOSITE PRIMARY KEY on (user_id, collection_id) rather than a surrogate id — the pair
     * IS the identity, and making the database say so is what stops two rows for one book
     * ever existing to disagree with each other. Both cascade: a deleted account keeps no
     * bookmarks, and a book that leaves the library takes its place-markers with it.
     *
     * `track_id` cascades too, which is the one that needs thinking about. A chapter can
     * vanish between scans (a re-rip, a renamed file the content hash could not match), and
     * the honest answer then is that the bookmark is gone rather than pointing at a chapter
     * that no longer exists — a resume that lands on the wrong story is worse than one that
     * starts the book again. The scanner's relink-to-a-clone path already saves the common
     * case before it gets here.
     */
    public function up(): void
    {
        Schema::create('audiobook_bookmarks', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignUuid('track_id')->constrained('tracks')->cascadeOnDelete();
            $table->primary(['user_id', 'collection_id']);

            // Milliseconds INTO THAT CHAPTER, not into the book. The chapter is the addressable
            // thing — it is what the player loads — and a book-relative offset would have to be
            // recomputed from every preceding duration, which are nullable.
            $table->unsignedBigInteger('position_ms')->default(0);

            // No created_at: what matters is when the reader was last here, and a bookmark that
            // remembered when it was first set would answer a question nobody asks.
            $table->timestamp('updated_at')->nullable();

            // "Which books am I in the middle of", newest first — the query a Continue
            // Listening shelf will want, and cheap to index now while the table is empty.
            $table->index(['user_id', 'updated_at']);

            // Backs the cascade from a chapter, which Postgres does not index for us.
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_bookmarks');
    }
};
