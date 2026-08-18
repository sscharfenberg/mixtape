<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tracks.year` — the year the FILE claims, ADDED BESIDE the year its container claims.
 *
 * THE YEAR CONTINUES TO LIVE ON COLLECTIONS. `collections.year` is the album's year and stays the
 * one a page shows: a year is a fact about a release, and that reading is unchanged. What this
 * column adds is the EVIDENCE that fact is drawn from — the tag is per file, so a container's year
 * is a conclusion about its files, and a conclusion should not be the only copy of what it was
 * concluded from.
 *
 * Two things followed from a file's year having nowhere to go, and both were bugs rather than
 * trade-offs: a corrected tag was read on every scan and dropped, because there was no column to
 * write it to; and an album whose files legitimately disagree had no way to record that they do.
 *
 * NULLABLE, and NULL on every existing row until the file behind it is read again — a scan only
 * re-reads files whose `(size, mtime)` moved, so this column fills in over time or all at once
 * with `app:update --reread`. A missing year here is therefore "not read yet" as well as "the tag
 * has none", which is the second reason no page reads it: the container's year is the answer
 * shown, and it is reconciled from these values rather than replaced by them
 * (LibraryScanService::syncCollectionYears).
 *
 * `unsignedSmallInteger` rather than Laravel's `year()`, which maps to a driver-specific type
 * (data-model.md → the portability notes). On Postgres it lands as a signed `smallint`, so the
 * ceiling is 32767 — enough for every recording, and for the mis-tagged 1882 the audit turned up.
 * Adding it is a catalogue change and nothing else: nullable with no default, so no table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('disc');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('year');
        });
    }
};
