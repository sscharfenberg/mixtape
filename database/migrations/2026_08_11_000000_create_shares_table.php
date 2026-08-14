<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `shares` — a link that lets someone WITHOUT AN ACCOUNT listen to one subject
     * (docs/sharing.md). The row IS the capability: `GET /s/{id}` finds it, and the
     * recipient may play what it names and nothing else until `valid_until` passes.
     *
     * A ROW RATHER THAN A SIGNED URL, and that is the decision this table exists to
     * record: a signature is an assertion, so there is nothing to revoke short of
     * rotating APP_KEY (which voids every session and every password-reset link).
     * A row can be deleted, and its expiry edited.
     *
     * THE PRIMARY KEY IS THE SECRET. `HasUuids` emits UUIDv7 on Laravel 13 — 74 bits
     * of randomness behind a rate-limited endpoint — so the capability is sound, and
     * using the PK directly buys route-model binding and `whereUuid` for free. Stored
     * unhashed, unlike `invites.token`: an invite code is shown once, while a share is
     * re-copied from the owner's list weeks later, and a digest cannot be re-displayed.
     *
     * FOUR REAL FKs RATHER THAN A POLYMORPHIC PAIR, because the point is `cascade`:
     * when a rescan drops a track or an album, its shares go with it. A morph column
     * has no referential integrity, so it would leave links resolving to a page that
     * cannot be built. `user_id` cascades too — delete the account and the links it
     * handed out stop working.
     *
     * ALL FOUR SUBJECT COLUMNS ARE CREATED TOGETHER, even where the app gains the mint path
     * for one later, precisely so the CHECK below is written once: adding a subject column
     * afterwards means dropping and re-adding the constraint on a live table.
     * There is deliberately no `genre_id` at all — "listen to this genre" is a different
     * kind of act from "listen to this", and was not asked for.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('shares', function (Blueprint $table) {
            $table->uuid('id')->primary(); // the URL secret — see the note above

            // Who minted it. Cascade is true ownership, as on `playlists`.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // The subject, as exactly one of four. Cascade on all of them: a share of
            // something the scanner has removed is a dead link, and a dead link is
            // better deleted than left to 500 on the guest page.
            $table->foreignUuid('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('collection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('artist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('playlist_id')->nullable()->constrained()->cascadeOnDelete();

            // Who it was for, in the minter's own words — the `invites.note` precedent. It is
            // deliberately never shown to the recipient: the owner's list is what reads it.
            $table->string('note', 255)->nullable();

            $table->timestamp('valid_until');
            $table->timestamps();

            // Backs the "My shares" list (a user's own rows, live ones first) and the
            // user-delete cascade check. Postgres does not index the referencing side
            // of a FK, so this is also `user_id`'s index.
            $table->index(['user_id', 'valid_until']);

            // The four subject FKs have no standalone index on purpose: nothing looks
            // a share up BY its subject — the guest page arrives with the share's own
            // id, and the mint route's "do I already have a live one for this?" query
            // is keyed on the user first, which the composite above already serves.
        });

        if ($pgsql) {
            // Exactly one subject, counted rather than spelled out as six pairwise
            // exclusions: `NOT NULL::int` sums to 1 only when precisely one is set,
            // which also rejects the all-null row a plain "not both" chain would let
            // through. sqlite (the test connection) skips it, as the collections and
            // tracks CHECKs do — the constraint is a production guarantee, and the
            // suite proves the app never tries to violate it.
            DB::statement(
                'ALTER TABLE shares ADD CONSTRAINT shares_one_subject_ck CHECK ('
                .'(track_id IS NOT NULL)::int + (collection_id IS NOT NULL)::int + '
                .'(artist_id IS NOT NULL)::int + (playlist_id IS NOT NULL)::int = 1)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
