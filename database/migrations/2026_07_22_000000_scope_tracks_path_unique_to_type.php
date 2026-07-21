<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tracks.path` now stores the location RELATIVE to its area root (e.g.
     * `Artist/Album/01.mp3`), not the absolute server path — so relocating the
     * collection (a new `MIXTAPE_*_PATH`) is a fast-path no-op instead of a full
     * re-hash, and the DB never bakes in a machine-specific path.
     *
     * Relative paths CAN collide across areas (music `Foo/1.mp3` vs. audiobook
     * `Foo/1.mp3` are different files), so the uniqueness anchor moves from
     * `path` alone to `(type, path)`. `type` maps 1:1 to an area, so this still
     * enforces "one file per area ⇒ one row" while letting each area keep its
     * own `Foo/1.mp3`.
     *
     * Existing rows still hold absolute paths — globally unique, so the new
     * composite index accepts them as-is; the next `app:update` rewrites them to
     * relative via the content-hash rename-match, ids preserved.
     */
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropUnique(['path']);     // tracks_path_unique
            $table->unique(['type', 'path']); // tracks_type_path_unique
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropUnique(['type', 'path']);
            $table->unique(['path']);
        });
    }
};
