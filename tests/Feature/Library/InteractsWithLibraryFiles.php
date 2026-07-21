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

    protected function makeLibraryRoot(): void
    {
        $this->root = sys_get_temp_dir().'/mixtape-lib-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        config(['mixtape.library.paths.music' => $this->root]);
        config(['mixtape.scan.extensions' => ['mp3']]);
    }

    protected function removeLibraryRoot(): void
    {
        if (! isset($this->root) || ! is_dir($this->root)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->root);
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
