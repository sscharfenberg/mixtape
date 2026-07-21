<?php

namespace App\Services\Library\Contracts;

use App\Services\Library\TrackMetadata;

/**
 * Reads one audio file into a normalised TrackMetadata (tags + stream fields +
 * the audio-frame content hash). An interface so the scanner never touches a
 * concrete tag library directly: production binds Id3TagReader (getID3), tests
 * bind a fake that returns canned metadata without needing real audio.
 */
interface TagReader
{
    /**
     * @param  string  $absolutePath  absolute path to the audio file
     *
     * @throws \RuntimeException when the file cannot be parsed / has no audio
     */
    public function read(string $absolutePath): TrackMetadata;
}
