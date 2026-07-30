<?php

namespace Tests\Feature\Media;

use App\Models\Collection;
use App\Models\Track;
use App\Services\Media\CoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The derived cover cache's own housekeeping (CoverService::pruneCache) — what it drops,
 * what it must never drop, and what it does when the filesystem says no.
 *
 * That last one is why this file exists rather than a couple of assertions bolted onto
 * the cleanup tests. The invalidation shipped as a silent no-op on the dev box: the cache
 * directory is created at runtime by the web server, so it belongs to www-data, while
 * `app:update` there runs as the admin user — and deleting a file needs write permission
 * on its DIRECTORY. Every `@unlink` failed, returned false, and was counted as "nothing to
 * do", so the scan reported `0 cached cover(s) invalidated` while serving stale artwork.
 */
class CoverCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = storage_path('app/private/covers');
        File::deleteDirectory($this->cache);
    }

    /** Restore write permission before deleting, or the read-only test leaves the dir behind. */
    protected function tearDown(): void
    {
        if (is_dir($this->cache)) {
            @chmod($this->cache, 0o755);
        }

        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    /** Plant a cover cache entry under the given file name, as a request would have. */
    private function cacheEntry(string $name): string
    {
        $path = $this->cache.'/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'scaled-jpeg');

        return $path;
    }

    public function test_the_sweep_drops_orphans_and_keeps_live_entries(): void
    {
        $track = Track::factory()->create();
        $album = Collection::factory()->create();

        $live = $this->cacheEntry($track->id.'.jpg');
        $liveAlbum = $this->cacheEntry('album-'.$album->id.'-1700000000.jpg');
        // Ids that are not in the database: the track/album they were extracted for is
        // gone. `migrate:fresh` + a rescan makes EVERY entry an orphan in one step, since
        // a rebuilt row gets a fresh UUID — which is how the real cache grew a backlog.
        $orphan = $this->cacheEntry('019f0000-0000-7000-8000-000000000000.jpg');
        $orphanAlbum = $this->cacheEntry('album-019f0000-0000-7000-8000-000000000001-1700000000.jpg');
        // Not one of ours at all — this directory belongs to the cache alone.
        $stray = $this->cacheEntry('notes.jpg');

        $result = app(CoverService::class)->pruneCache();

        $this->assertSame(3, $result['removed']);
        $this->assertSame(0, $result['refused']);
        $this->assertFileExists($live);
        $this->assertFileExists($liveAlbum);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileDoesNotExist($orphanAlbum);
        $this->assertFileDoesNotExist($stray);
    }

    public function test_the_sweep_keeps_only_an_albums_newest_variant(): void
    {
        // `album-<id>-<mtime>.jpg` keys on the source image's mtime, so replacing the art
        // in place leaves the old scaled copy behind — unreachable, because only the
        // current mtime is ever requested, but still on disk.
        $album = Collection::factory()->create();

        $old = $this->cacheEntry('album-'.$album->id.'-1700000000.jpg');
        $older = $this->cacheEntry('album-'.$album->id.'-1600000000.jpg');
        $current = $this->cacheEntry('album-'.$album->id.'-1800000000.jpg');

        $result = app(CoverService::class)->pruneCache();

        $this->assertSame(2, $result['removed']);
        $this->assertFileExists($current);
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($older);
    }

    public function test_the_sweep_is_a_no_op_with_nothing_to_do(): void
    {
        // An absent cache directory must not be an error (a fresh install has never served
        // a cover), and a cache holding only live entries must not lose any — these files
        // cost real work to rebuild, which is why the sweep is surgical rather than a wipe.
        $this->assertSame(['removed' => 0, 'refused' => 0], app(CoverService::class)->pruneCache());

        $live = $this->cacheEntry(Track::factory()->create()->id.'.jpg');

        $this->assertSame(['removed' => 0, 'refused' => 0], app(CoverService::class)->pruneCache());
        $this->assertFileExists($live);
    }

    public function test_a_cache_it_cannot_write_to_is_reported_rather_than_counted_as_clean(): void
    {
        // The bug this whole guard exists for. A read-only cache directory stands in for
        // the real cause (a directory owned by another user): either way `unlink` is
        // refused, and the one thing that must NOT happen is the sweep reporting a clean
        // run — that is what let stale artwork be served indefinitely.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root, which ignores directory permissions');
        }

        $orphan = $this->cacheEntry('019f0000-0000-7000-8000-000000000000.jpg');
        chmod($this->cache, 0o555);

        $result = app(CoverService::class)->pruneCache();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['refused'], 'a refused delete must be counted, not silently dropped');
        $this->assertFileExists($orphan);
    }

    public function test_forgetting_one_track_is_refused_quietly_but_not_falsely(): void
    {
        // The same guard on the single-file path the scanner uses for a re-tag: it must
        // report "no" rather than "done" when it could not delete.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root, which ignores directory permissions');
        }

        $track = Track::factory()->create();
        $cached = $this->cacheEntry($track->id.'.jpg');
        chmod($this->cache, 0o555);

        $this->assertFalse(app(CoverService::class)->forget($track));
        $this->assertFileExists($cached);
    }
}
