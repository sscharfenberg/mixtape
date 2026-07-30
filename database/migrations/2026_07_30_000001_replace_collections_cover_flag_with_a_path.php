<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `collections.cover` (boolean) → `collections.cover_path` (nullable string).
 *
 * The boolean was documented as "has a Folder.jpg alongside it" and was never written
 * by anything: 0 of the live collection's 923 albums had it set, so there is no data
 * to preserve and the column can simply go.
 *
 * A path rather than a flag, because the two grains need different facts. A TRACK's
 * art is inside the file whose path is already stored, so `tracks.cover` (a boolean)
 * plus `tracks.path` is a complete location — a path column there would only repeat
 * `path`, which is why that one stays as it is. An ALBUM's art is a *sibling file
 * whose name cannot be derived*: measured on the real collection it is `folder.jpg`
 * 923 times, `cover.jpg` 63 times, and sometimes named after the album itself. That
 * name is the one thing worth storing — and storing it moves the resolution (the
 * candidate-name order, the case-insensitive match, the lone-image rule; see
 * CoverService::directoryImage) from every page render to once per scan, without
 * writing a single image to disk. Pre-extracting the 12060 embedded pictures instead
 * would have cost ~330 MB and minutes per scan to materialise art nobody may look at.
 *
 * 512 to match `tracks.path`, and area-relative for the same reason: relocating the
 * collection must not invalidate the column.
 *
 * The trade is staleness — art added without a rescan is unseen until the next
 * `app:update`. Accepted because cover art arrives with the music it belongs to, and
 * because a *stale* path still degrades safely: the cover route re-resolves live when
 * the recorded file has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('cover');
        });

        Schema::table('collections', function (Blueprint $table) {
            // Area-relative path of the album's directory image, or NULL for an album
            // that has none (15 of the 951 real album directories hold no image at
            // all, and an album whose only art is embedded is null here too — that
            // half is answered by `tracks.cover`).
            $table->string('cover_path', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('cover')->default(false);
        });
    }
};
