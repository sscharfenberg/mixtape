<?php

namespace Tests\Feature\Library;

use Tests\TestCase;

/**
 * `app:clean` — the standalone cleanup command. The deletion behaviour is
 * covered by LibraryCleanupServiceTest; here we check the command wiring:
 * area resolution, exit codes, and that it actually removes junk.
 */
class CleanLibraryCommandTest extends TestCase
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

    public function test_it_deletes_junk_and_keeps_media(): void
    {
        $junk = $this->rawFile('album/.DS_Store');
        $apple = $this->rawFile('album/._01.mp3');
        $song = $this->rawFile('album/01.mp3', 'audio');

        $this->artisan('app:clean', ['--area' => ['music']])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($junk);
        $this->assertFileDoesNotExist($apple);
        $this->assertFileExists($song);
    }

    public function test_unknown_area_is_rejected(): void
    {
        $this->artisan('app:clean', ['--area' => ['bogus']])
            ->assertExitCode(2); // Command::INVALID
    }
}
