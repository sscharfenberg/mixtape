<?php

namespace Tests\Feature\Library;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Play;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use App\Services\Library\Contracts\TagReader;
use App\Services\Library\LibraryScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The content-hash diff. These are the behaviours truncate-and-rebuild could not
 * give (data-model.md → "the one fact"): stable ids across renames and re-tags,
 * clones as distinct rows, orphan pruning, relink-then-cascade, and per-file
 * resilience. Driven by a FakeTagReader so no real audio is needed.
 */
class LibraryScanServiceTest extends TestCase
{
    use InteractsWithLibraryFiles, RefreshDatabase;

    private LibraryScanService $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
        $this->app->instance(TagReader::class, new FakeTagReader);
        $this->scanner = $this->app->make(LibraryScanService::class);
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    private function scan(): void
    {
        $this->scanner->scan([TrackType::Music]);
    }

    public function test_first_scan_inserts_tracks_collections_and_taxonomy(): void
    {
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'The Band', 'album' => 'Debut', 'genre' => 'Rock', 'track' => 1, 'year' => 1999]);
        $this->media('rock/02.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'The Band', 'album' => 'Debut', 'genre' => 'Rock', 'track' => 2, 'year' => 1999]);

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(2, $summary->inserted());
        $this->assertSame(2, Track::count());
        // Two tracks, same album+artist → one deduped collection + one artist + one genre.
        $this->assertSame(1, Collection::count());
        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Genre::count());

        $album = Collection::first();
        $this->assertSame('Debut', $album->name);
        $this->assertSame(1999, $album->year);
        $this->assertSame(Artist::first()->id, $album->album_artist_id);
    }

    public function test_unchanged_file_is_skipped_on_rescan_keeping_its_id(): void
    {
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->scan();
        $id = Track::first()->id;

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(0, $summary->inserted() + $summary->updated() + $summary->renamed() + $summary->deleted());
        $this->assertSame($id, Track::first()->id);
    }

    public function test_retag_at_the_same_path_keeps_id_and_updates_tags(): void
    {
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'Old Title', 'artist' => 'A', 'album' => 'Alb', 'genre' => 'Rock']);
        $this->scan();
        $id = Track::first()->id;

        // Same audio (hash h1), new tags — and a bumped mtime so it misses the fast-path.
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'New Title', 'artist' => 'A', 'album' => 'Alb', 'genre' => 'Jazz'], time() + 5);

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->updated());
        $this->assertSame(0, $summary->inserted() + $summary->renamed() + $summary->deleted());
        $track = Track::first();
        $this->assertSame($id, $track->id, 'the re-tag must keep the same row id');
        $this->assertSame('New Title', $track->name);
        $this->assertSame('Jazz', $track->genre->name);
        // The abandoned "Rock" genre is now orphaned and pruned.
        $this->assertNull(Genre::where('name', 'Rock')->first());
    }

    public function test_moved_file_keeps_id_via_content_hash(): void
    {
        $old = $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->scan();
        $id = Track::first()->id;

        // Move: same audio, new path.
        unlink($old);
        $this->media('b/99.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->renamed());
        $this->assertSame(0, $summary->inserted() + $summary->deleted());
        $this->assertSame(1, Track::count());
        $track = Track::first();
        $this->assertSame($id, $track->id, 'the move must keep the same row id');
        $this->assertStringEndsWith('b/99.mp3', $track->path);
    }

    public function test_deleted_file_is_removed_and_orphan_taxonomy_pruned(): void
    {
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'AlbA', 'genre' => 'Rock']);
        $keep = $this->media('b/01.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'B', 'album' => 'AlbB', 'genre' => 'Jazz']);
        $this->scan();
        $this->assertSame(2, Track::count());

        unlink($this->root.'/a/01.mp3');

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->deleted());
        $this->assertSame(1, Track::count());
        // A's taxonomy is orphaned → pruned; B's survives.
        $this->assertNull(Artist::where('name', 'A')->first());
        $this->assertNull(Collection::where('name', 'AlbA')->first());
        $this->assertNull(Genre::where('name', 'Rock')->first());
        $this->assertNotNull(Artist::where('name', 'B')->first());
        $this->assertNotNull(Genre::where('name', 'Jazz')->first());
        $this->assertFileExists($keep);
    }

    public function test_identical_audio_in_two_files_is_two_rows_sharing_a_hash(): void
    {
        $this->media('album/01.mp3', ['hash' => 'dup', 'title' => 'Song', 'artist' => 'A', 'album' => 'Album']);
        $this->media('bestof/01.mp3', ['hash' => 'dup', 'title' => 'Song', 'artist' => 'A', 'album' => 'Best Of']);

        $this->scan();

        $this->assertSame(2, Track::count());
        $tracks = Track::all();
        $this->assertSame(['dup', 'dup'], $tracks->pluck('content_hash')->all());
        $this->assertSame(1, $tracks->first()->clones()->count());
    }

    public function test_orphan_relinks_playlist_and_plays_to_a_surviving_clone(): void
    {
        $doomed = $this->media('a/01.mp3', ['hash' => 'dup', 'title' => 'Song', 'artist' => 'A', 'album' => 'Album']);
        $this->media('b/01.mp3', ['hash' => 'dup', 'title' => 'Song', 'artist' => 'A', 'album' => 'Best Of']);
        $this->scan();

        $doomedRow = Track::where('path', $doomed)->firstOrFail();
        $survivor = Track::where('path', '!=', $doomed)->firstOrFail();

        $user = User::factory()->create();
        $playlist = $user->playlists()->create(['name' => 'Mix', 'position' => 0]);
        $playlist->playlistTracks()->create(['track_id' => $doomedRow->id, 'position' => 0]);
        Play::create(['user_id' => $user->id, 'track_id' => $doomedRow->id, 'played_at' => now()]);

        // The surviving clone remains on disk; the doomed one is deleted.
        unlink($doomed);
        $this->scanner->scan([TrackType::Music]);

        $this->assertNull(Track::find($doomedRow->id), 'the orphaned row is hard-deleted');
        // The playlist entry and the play were repointed to the surviving clone, not cascaded away.
        $this->assertSame(1, PlaylistTrack::count());
        $this->assertSame($survivor->id, PlaylistTrack::first()->track_id);
        $this->assertSame(1, Play::count());
        $this->assertSame($survivor->id, Play::first()->track_id);
    }

    public function test_unreadable_file_is_skipped_and_does_not_abort_the_scan(): void
    {
        $this->media('a/good.mp3', ['hash' => 'h1', 'title' => 'Good', 'artist' => 'A', 'album' => 'Alb']);
        $this->media('a/bad.mp3', ['__fail' => true]);

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->inserted());
        $this->assertSame(1, $summary->errors());
        $this->assertSame(1, Track::count());

        // The skip is captured with its path + reason (never a silent drop).
        $skipped = $summary->results[TrackType::Music->value]->skipped;
        $this->assertCount(1, $skipped);
        $this->assertStringEndsWith('a/bad.mp3', $skipped[0]['path']);
        $this->assertNotSame('', $skipped[0]['reason']);
    }

    public function test_an_unconfigured_area_is_skipped_not_failed(): void
    {
        // The common "I have no podcasts" case: an empty path is not an error.
        config(['mixtape.library.paths.podcast_shows' => '']);

        $summary = $this->scanner->scan([TrackType::Podcast]);

        $this->assertSame(0, $summary->discovered());
        $this->assertSame(0, $summary->inserted() + $summary->deleted());
    }

    public function test_a_configured_but_missing_path_still_aborts(): void
    {
        // A non-empty path that isn't a directory is a real problem (typo /
        // dropped mount) — it must throw so the command alerts, never silently
        // scan zero files and orphan-delete the area.
        config(['mixtape.library.paths.music' => '/no/such/mixtape/dir']);

        $this->expectException(\RuntimeException::class);
        $this->scanner->scan([TrackType::Music]);
    }

    public function test_zero_files_in_a_populated_area_skips_pruning(): void
    {
        $a = $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $b = $this->media('a/02.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'A', 'album' => 'Alb']);
        $this->scan();
        $this->assertSame(2, Track::count());

        // The directory still exists but is now empty (e.g. a dropped mount).
        unlink($a);
        unlink($b);

        $summary = $this->scanner->scan([TrackType::Music]);

        // Guard: nothing is deleted or pruned — the library is left intact…
        $this->assertSame(0, $summary->discovered());
        $this->assertSame(0, $summary->deleted());
        $this->assertSame(2, Track::count());
        $this->assertNotNull(Artist::where('name', 'A')->first());
        // …and it is flagged so the command can escalate to an alert.
        $this->assertTrue($summary->results[TrackType::Music->value]->skippedEmpty);
        $this->assertSame(2, $summary->results[TrackType::Music->value]->protectedRows);
    }

    public function test_zero_files_with_no_existing_rows_is_a_harmless_noop(): void
    {
        // Empty area, empty DB → nothing to protect, nothing to do, no error.
        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(0, $summary->discovered());
        $this->assertSame(0, Track::count());
    }
}
