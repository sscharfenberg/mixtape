<?php

namespace Tests\Feature\Library;

use App\Enums\TrackType;
use App\Models\Collection;
use App\Models\Track;
use App\Services\Library\LibraryCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The cleanup step deletes OS/Samba junk from the shares (config masks) before
 * the scan, and leaves real media + folder art alone. It also sweeps the derived
 * cover cache, which is app storage rather than share content — hence RefreshDatabase
 * here: what the sweep keeps depends on which ids still exist.
 */
class LibraryCleanupServiceTest extends TestCase
{
    use InteractsWithLibraryFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        File::deleteDirectory(storage_path('app/private/covers'));
        parent::tearDown();
    }

    /** Plant a cover cache entry under the given file name, as a request would have. */
    private function cacheEntry(string $name): string
    {
        $path = storage_path('app/private/covers/'.$name);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'scaled-jpeg');

        return $path;
    }

    public function test_it_removes_junk_files_and_keeps_media_and_art(): void
    {
        // Junk (every configured mask, including nested + dotfiles).
        $junk = [
            $this->rawFile('Thumbs.db'),
            $this->rawFile('album/._hidden'),
            $this->rawFile('album/AlbumArtSmall.jpg'),
            $this->rawFile('album/tab.gp5'),
            $this->rawFile('album/.DS_Store'),
            $this->rawFile('album/.@__smb1'),
            $this->rawFile('album/.smbdelete-tmp'),
        ];

        // Keep these.
        $song = $this->rawFile('album/01.mp3', 'audio');
        $art = $this->rawFile('album/Folder.jpg', 'jpeg');

        $removed = app(LibraryCleanupService::class)->clean([TrackType::Music]);

        $this->assertSame(count($junk), $removed);

        foreach ($junk as $path) {
            $this->assertFileDoesNotExist($path);
        }

        $this->assertFileExists($song);
        $this->assertFileExists($art);
    }

    public function test_a_missing_area_path_is_skipped_not_fatal(): void
    {
        config(['mixtape.library.paths.music' => '/no/such/mixtape/path']);

        $removed = app(LibraryCleanupService::class)->clean([TrackType::Music]);

        $this->assertSame(0, $removed);
    }

    public function test_the_cover_sweep_drops_orphans_and_keeps_live_entries(): void
    {
        $track = Track::factory()->create();
        $album = Collection::factory()->create();

        $live = $this->cacheEntry($track->id.'.jpg');
        $liveAlbum = $this->cacheEntry('album-'.$album->id.'-1700000000.jpg');
        // Ids that are not in the database: the track/album they were extracted for has
        // been deleted since. This is the historical junk — nothing dropped its own
        // entries before this sweep existed.
        $orphan = $this->cacheEntry('019f0000-0000-7000-8000-000000000000.jpg');
        $orphanAlbum = $this->cacheEntry('album-019f0000-0000-7000-8000-000000000001-1700000000.jpg');
        // Not one of ours at all — this directory belongs to the cache alone.
        $stray = $this->cacheEntry('notes.jpg');

        $removed = app(LibraryCleanupService::class)->pruneCoverCache();

        $this->assertSame(3, $removed);
        $this->assertFileExists($live);
        $this->assertFileExists($liveAlbum);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileDoesNotExist($orphanAlbum);
        $this->assertFileDoesNotExist($stray);
    }

    public function test_the_cover_sweep_keeps_only_an_albums_newest_variant(): void
    {
        // `album-<id>-<mtime>.jpg` keys on the source image's mtime, so replacing the
        // art in place leaves the old scaled copy behind — unreachable, because only the
        // current mtime is ever requested, but still on disk. Only the newest survives.
        $album = Collection::factory()->create();

        $old = $this->cacheEntry('album-'.$album->id.'-1700000000.jpg');
        $older = $this->cacheEntry('album-'.$album->id.'-1600000000.jpg');
        $current = $this->cacheEntry('album-'.$album->id.'-1800000000.jpg');

        $removed = app(LibraryCleanupService::class)->pruneCoverCache();

        $this->assertSame(2, $removed);
        $this->assertFileExists($current);
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($older);
    }

    public function test_the_cover_sweep_is_a_no_op_with_nothing_to_do(): void
    {
        // Two things at once: an absent cache directory must not be an error (a fresh
        // install has never served a cover), and a cache holding only live entries must
        // not lose any — this cache costs real work to rebuild, which is why the sweep
        // is surgical instead of legacy's wipe-on-rescan.
        $this->assertSame(0, app(LibraryCleanupService::class)->pruneCoverCache());

        $live = $this->cacheEntry(Track::factory()->create()->id.'.jpg');

        $this->assertSame(0, app(LibraryCleanupService::class)->pruneCoverCache());
        $this->assertFileExists($live);
    }
}
