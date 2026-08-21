<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

/**
 * One collection holding repeated `(disc, track)` numbers — see {@see TrackNumberCollisions}.
 *
 * The two checks that read it ask the same question of it, in opposite directions: whether the
 * colliding files are spread across directories. That one boolean is the difference between
 * "renumber these tags" and "these are two different albums".
 */
final readonly class Collision
{
    /**
     * @param  string[]  $numbers  the repeated numbers, as "disc/track"
     * @param  string[]  $folders  the distinct directories the colliding files sit in
     * @param  bool  $discTagged  whether ANY of the colliding files carries a disc number — false
     *                            means a multi-disc set that was never disc-tagged, which spans
     *                            folders and collides on every track exactly as a merged album
     *                            does, and needs the opposite advice
     */
    public function __construct(
        public string $collectionId,
        public string $name,
        public array $numbers,
        public array $folders,
        public bool $discTagged = true,
    ) {}

    /** Whether the collision crosses directories, which is what makes it two albums rather than bad tags. */
    public function spansFolders(): bool
    {
        return count($this->folders) > 1;
    }
}
