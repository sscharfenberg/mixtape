<?php

namespace Tests\Feature\Library;

use App\Enums\TrackType;
use App\Services\Library\LibraryCleanupService;
use Tests\TestCase;

/**
 * The cleanup step deletes OS/Samba junk from the shares (config masks) before
 * the scan, and leaves real media + folder art alone.
 */
class LibraryCleanupServiceTest extends TestCase
{
    use InteractsWithLibraryFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
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
}
