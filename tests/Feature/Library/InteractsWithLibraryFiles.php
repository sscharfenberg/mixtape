<?php

namespace Tests\Feature\Library;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Temp-directory + fake-media-file helpers shared by the library scan/cleanup
 * tests. The temp dir is wired up as the `music` library path.
 */
trait InteractsWithLibraryFiles
{
    protected string $root;

    /** @var string[] extra temp roots (e.g. an audiobooks area) to clean up */
    protected array $extraRoots = [];

    protected function makeLibraryRoot(): void
    {
        $this->root = sys_get_temp_dir().'/mixtape-lib-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        config(['mixtape.library.paths.music' => $this->root]);
        config(['mixtape.scan.extensions' => ['mp3']]);
    }

    /** Spin up a second area (audiobooks) pointed at its own temp dir. */
    protected function makeAudiobookRoot(): string
    {
        $dir = $this->root.'-audiobooks';
        mkdir($dir, 0777, true);
        config(['mixtape.library.paths.audiobooks' => $dir]);
        $this->extraRoots[] = $dir;

        return $dir;
    }

    protected function removeLibraryRoot(): void
    {
        foreach (array_merge([$this->root ?? ''], $this->extraRoots) as $dir) {
            $this->removeDir($dir);
        }
    }

    protected function removeDir(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Write a fake media file (a JSON body the FakeTagReader understands) at a
     * path relative to the library root. Returns its absolute path.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function media(string $relative, array $meta = [], ?int $mtime = null): string
    {
        $path = $this->root.'/'.ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($meta));

        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    /** Write a raw (non-media) file at a path relative to the root — for cleanup tests. */
    protected function rawFile(string $relative, string $contents = 'x'): string
    {
        $path = $this->root.'/'.ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);

        return $path;
    }
}
