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

        $doomedRow = Track::where('path', 'a/01.mp3')->firstOrFail();      // stored relative
        $survivor = Track::where('path', 'b/01.mp3')->firstOrFail();

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

    public function test_path_is_stored_relative_to_the_area_root(): void
    {
        $this->media('Artist/Album/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->scan();

        $track = Track::firstOrFail();
        $this->assertSame('Artist/Album/01.mp3', $track->path);           // relative, no server prefix
        $this->assertSame($this->root.'/Artist/Album/01.mp3', $track->absolutePath());
    }

    public function test_relocating_the_root_is_a_fast_path_noop(): void
    {
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->media('a/02.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'A', 'album' => 'Alb']);
        $this->scan();

        $before = Track::query()->orderBy('path')->pluck('id', 'path')->all();
        $this->assertSame(['a/01.mp3', 'a/02.mp3'], array_keys($before));

        // Actually move the whole collection to a new root (rename preserves the
        // files' mtimes + contents), then point the config there.
        $moved = $this->root.'-moved';
        rename($this->root, $moved);
        $this->root = $moved;                          // so tearDown cleans the new location
        config(['mixtape.library.paths.music' => $moved]);

        $summary = $this->scanner->scan([TrackType::Music]);

        // Relative paths still match on (path, size, mtime): nothing changes.
        $this->assertSame(0, $summary->inserted() + $summary->updated() + $summary->renamed() + $summary->deleted());
        $this->assertSame($before, Track::query()->orderBy('path')->pluck('id', 'path')->all());
    }

    public function test_same_relative_path_in_two_areas_are_distinct_rows(): void
    {
        // music/Foo/01.mp3 and audiobooks/Foo/01.mp3 — identical relative path,
        // different areas. UNIQUE(path) would have collided; UNIQUE(type, path)
        // keeps them as two rows.
        $this->media('Foo/01.mp3', ['hash' => 'm1', 'title' => 'Song', 'artist' => 'A', 'album' => 'Alb']);

        $ab = $this->makeAudiobookRoot();
        @mkdir($ab.'/Foo', 0777, true);
        file_put_contents($ab.'/Foo/01.mp3', json_encode([
            'hash' => 'b1', 'title' => 'Chapter', 'composer' => 'Author', 'artist' => 'Narrator', 'album' => 'Book',
        ]));

        $this->scanner->scan([TrackType::Music, TrackType::Audiobook]);

        $this->assertSame(2, Track::count());
        $this->assertSame(1, Track::where('type', TrackType::Music)->where('path', 'Foo/01.mp3')->count());
        $this->assertSame(1, Track::where('type', TrackType::Audiobook)->where('path', 'Foo/01.mp3')->count());
    }

    public function test_a_scan_populates_the_folded_search_columns(): void
    {
        // The guarantee behind "migrate:fresh + app:update on a clean database":
        // every row the scanner writes must come out searchable, with no backfill
        // step. The scanner never touches these columns itself — HasFoldedName
        // hangs them off the `name` mutator, and this is what proves the scanner's
        // write paths all go through it (Track::create + firstOrCreate here).
        $this->media('metal/01.mp3', [
            'hash' => 'f1', 'title' => 'Kroniksy', 'artist' => 'Mgła', 'album' => 'Straße der Besten', 'genre' => 'Métal',
        ]);

        $this->scan();

        $track = Track::sole();
        $this->assertSame('kroniksy', $track->name_fold);
        $this->assertSame('mgla', Artist::sole()->name_fold);
        $this->assertSame('strasse der besten', Collection::sole()->name_fold);
        $this->assertSame('metal', Genre::sole()->name_fold);
    }

    public function test_a_retag_refolds_the_name(): void
    {
        // The other half: a re-tag is an UPDATE through fill()->save(), so the fold
        // must follow the new title. A stale fold is a silent search miss — the row
        // simply stops being findable under its own name, with nothing failing.
        $this->media('metal/01.mp3', ['hash' => 'f1', 'title' => 'Wrong Titel', 'artist' => 'Mgła', 'album' => 'Exercises']);
        $this->scan();

        $this->media('metal/01.mp3', ['hash' => 'f1', 'title' => 'Gruzia', 'artist' => 'Mgła', 'album' => 'Exercises']);
        $this->scan();

        $this->assertSame('gruzia', Track::sole()->name_fold);
    }

    public function test_the_scan_records_the_albums_directory_image(): void
    {
        // The step that keeps cover lookup off the filesystem at request time: the
        // resolved image is stored AREA-RELATIVE, like every other path here, so
        // moving the collection to a new root doesn't invalidate it.
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);
        $this->rawFile('rock/folder.jpg', 'jpeg-bytes');

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->covers());
        $this->assertSame('rock/folder.jpg', Collection::sole()->cover_path);
    }

    public function test_it_records_a_lone_differently_named_image(): void
    {
        // Art named after the album, which is only safe to take because it is the
        // directory's ONLY image — the same rule CoverService applies live.
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);
        $this->rawFile('rock/Debut.jpg', 'jpeg-bytes');

        $this->scan();

        $this->assertSame('rock/Debut.jpg', Collection::sole()->cover_path);
    }

    public function test_it_records_nothing_for_a_directory_with_no_image(): void
    {
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertNull(Collection::sole()->cover_path);
        // Nothing to record is not a change — an all-null area must report 0, or the
        // counter would read as work done on every scan of a coverless library.
        $this->assertSame(0, $summary->covers());
    }

    public function test_a_multi_disc_set_records_the_first_discs_image(): void
    {
        // Discs in subdirectories: the album resolves to disc 1's image, where a
        // ripper puts the album art — and deterministically, not "whichever file the
        // storage engine returned first".
        $this->media('rock/[Disc 1]/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut', 'disc' => 1, 'track' => 1]);
        $this->media('rock/[Disc 2]/01.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'A', 'album' => 'Debut', 'disc' => 2, 'track' => 1]);
        $this->rawFile('rock/[Disc 1]/folder.jpg', 'disc-one');
        $this->rawFile('rock/[Disc 2]/folder.jpg', 'disc-two');

        $this->scan();

        $this->assertSame('rock/[Disc 1]/folder.jpg', Collection::sole()->cover_path);
    }

    public function test_a_steady_state_rescan_records_no_cover_changes(): void
    {
        // The counter is the cheap signal that this step does nothing when nothing
        // moved — the same philosophy as pass 1's fast-path.
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);
        $this->rawFile('rock/folder.jpg', 'jpeg-bytes');
        $this->scan();

        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(0, $summary->covers());
        $this->assertSame('rock/folder.jpg', Collection::sole()->cover_path);
    }

    public function test_removing_the_image_clears_the_recorded_path(): void
    {
        // A recorded path that has gone must be UNRECORDED, not left behind: a
        // listing decides whether to show a thumbnail from this column, and a
        // lingering path would advertise an image that 404s.
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);
        $this->rawFile('rock/folder.jpg', 'jpeg-bytes');
        $this->scan();

        unlink($this->root.'/rock/folder.jpg');
        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->covers());
        $this->assertNull(Collection::sole()->cover_path);
    }

    public function test_a_renamed_image_is_re_recorded(): void
    {
        $this->media('rock/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Debut']);
        $this->rawFile('rock/folder.jpg', 'jpeg-bytes');
        $this->scan();

        unlink($this->root.'/rock/folder.jpg');
        $this->rawFile('rock/cover.jpg', 'jpeg-bytes');
        $summary = $this->scanner->scan([TrackType::Music]);

        $this->assertSame(1, $summary->covers());
        $this->assertSame('rock/cover.jpg', Collection::sole()->cover_path);
    }
}
