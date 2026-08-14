<?php

use App\Services\Search\FoldedSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fold columns `playlists` was left out of — the one migration the cross-kind
     * search needs (docs/search.md → "Playlists are the awkward one").
     *
     * The original fold migration covered `tracks`, `collections`, `artists`, `authors`,
     * `narrators` and `genres`, because those are the tables the LISTINGS search and
     * playlists have no listing search. Including them in `GET /search` changes that, and
     * the trap is that a plain `like` on `playlists.name` would technically RUN: that
     * column carries the database default collation rather than the nondeterministic
     * `case_insensitive` ICU one the taxonomy names wear, so nothing would error. It would
     * simply be the one search in the app that is case-insensitive and accent-SENSITIVE,
     * with no trigram index behind it — a silent inconsistency, which is worse than a
     * failure.
     *
     * TWO COLUMNS, not one. A playlist is matched on its blurb as well as its name (it is
     * the only kind with a second text field worth searching), so `description` gets a fold
     * of its own — `text` rather than `string`, mirroring the column it follows, and
     * nullable because a playlist need not carry a description at all. Kept in step by
     * App\Models\Concerns\HasFoldedDescription, the companion to the `name` mutator.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            // No ->collation() on either, exactly as the original fold migration explains:
            // a fold column must stay on the database default (deterministic) collation,
            // or Postgres would refuse LIKE on it just as it does on an ICU-collated name.
            // Nullable for the moment between adding them and the backfill below; the
            // model's mutators fill them for every row written after.
            $table->string('name_fold', 255)->nullable();
            $table->text('description_fold')->nullable();
        });

        $this->backfill();

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Already installed by the original fold migration; repeated because a migration
            // must not depend on another one having run in the same database.
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            // Trigram GIN, the only index a leading-wildcard LIKE can use. Both columns
            // get one: the search ORs across them, so an index on the name alone would
            // leave every keystroke scanning the table for the description half.
            DB::statement('CREATE INDEX playlists_name_fold_trgm ON playlists USING gin (name_fold gin_trgm_ops)');
            DB::statement('CREATE INDEX playlists_description_fold_trgm ON playlists USING gin (description_fold gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        // Dropping the columns takes their trigram indexes with them. `pg_trgm` stays
        // installed on purpose — it is database-wide and six other tables depend on it.
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn(['name_fold', 'description_fold']);
        });
    }

    /**
     * Fill both folds for the playlists that already exist.
     *
     * Row-at-a-time through the query builder rather than the Eloquent model, for the
     * reason the original fold backfill gives: a migration must keep working when the models
     * move on, and the fold is a PHP function no single UPDATE can express. A household's
     * playlists number in the dozens, so this is instant.
     */
    private function backfill(): void
    {
        DB::table('playlists')
            ->select('id', 'name', 'description')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('playlists')->where('id', $row->id)->update([
                        'name_fold' => FoldedSearch::fold($row->name),
                        'description_fold' => FoldedSearch::fold($row->description),
                    ]);
                }
            });
    }
};
