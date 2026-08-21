<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Track;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;

/**
 * Drive ONE check and read what it found — the shape every check test wants.
 *
 * Through the registry rather than by constructing a class, deliberately: `AuditCheck::check()`
 * is the seam a new check is wired into, so a test that instantiated the class directly would
 * pass for a check the command can never run.
 */
trait RunsAuditChecks
{
    /** Run one check over every area, as a full audit does. */
    protected function check(AuditCheck $case): CheckFindings
    {
        return $case->check()->run(new AuditScope(TrackType::cases()));
    }

    /**
     * The subjects a check reported, sorted so a test never asserts on query order.
     *
     * @return string[]
     */
    protected function subjects(AuditCheck $case): array
    {
        $subjects = array_map(
            fn (AuditFinding $finding) => $finding->subject,
            $this->check($case)->listed,
        );
        sort($subjects);

        return $subjects;
    }

    /**
     * An album with files at given paths, each carrying the attributes it needs.
     *
     * `cover_path` is set by default because the factory leaves it null and "no folder image" is
     * itself one of the checks — an unrelated test should not have to think about that.
     *
     * @param  array<string, array<string, mixed>>  $files  relative path => track attributes
     * @param  array<string, mixed>  $album
     */
    protected function album(string $name, array $files, array $album = []): Collection
    {
        $collection = Collection::factory()->create([
            'type' => CollectionType::Album,
            'name' => $name,
            'cover_path' => 'seed/folder.jpg',
            ...$album,
        ]);

        // ONE artist and ONE genre for the whole album, which is both what a real album looks
        // like and a hard requirement: `GenreFactory` draws from a short list through Faker's
        // `unique()`, whose 10,000-retry budget is shared by every call in the run — a per-track
        // genre exhausts it somewhere in the fifties and fails in whichever test got there first.
        $artist = Artist::factory()->create();
        $genre = Genre::factory()->create();

        foreach ($files as $path => $attributes) {
            Track::factory()->create([
                // The KEY, not `->for()`: the factory's audiobook state names a collection factory
                // of its own, and a `for()` relationship does not always beat a state's attribute —
                // measured, a chapter built that way was created under a second, invented book,
                // which then showed up as a finding in its own right. An explicit id is passed as
                // a late state and cannot lose.
                'collection_id' => $collection->id,
                'artist_id' => $artist->id,
                'genre_id' => $genre->id,
                'type' => TrackType::Music,
                'path' => $path,
                ...$attributes,
            ]);
        }

        return $collection;
    }

    /**
     * A song belonging to no album at all, which the factory cannot express on its own.
     *
     * The definition always builds a collection, so the FK has to be nulled explicitly — and a
     * track with no collection is a real state (`tracks.collection_id` is nullable) rather than a
     * broken fixture: it is what a file with no ALBUM tag scans as.
     */
    protected function orphanTrack(string $path): Track
    {
        return Track::factory()->create([
            'collection_id' => null,
            'type' => TrackType::Music,
            'path' => $path,
        ]);
    }

    /**
     * A book with chapters, the audiobook mirror of {@see album}.
     *
     * Through the factory's `audiobook()` state so the row satisfies the tracks type-guard CHECK
     * (a chapter has a narrator and no artist or genre), which is what makes these fixtures
     * exercise the same constraint production rows do.
     *
     * @param  array<string, array<string, mixed>>  $chapters  relative path => track attributes
     */
    protected function book(string $name, array $chapters): Collection
    {
        $collection = Collection::factory()->audiobook()->create([
            'name' => $name,
            'cover_path' => 'seed/folder.jpg',
        ]);

        // One narrator and one author for the whole book — see {@see album} on the shared
        // `unique()` budget, and because a book normally has exactly that.
        $narrator = Narrator::factory()->create();
        $author = Author::factory()->create();

        foreach ($chapters as $path => $attributes) {
            Track::factory()->audiobook()->create([
                // See {@see album} for why this is the key rather than `->for()`.
                'collection_id' => $collection->id,
                'narrator_id' => $narrator->id,
                'author_id' => $author->id,
                'path' => $path,
                ...$attributes,
            ]);
        }

        return $collection;
    }
}
