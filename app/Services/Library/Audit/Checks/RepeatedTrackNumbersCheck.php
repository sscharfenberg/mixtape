<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Collision;
use App\Services\Library\Audit\Contracts\Check;

/** Two files in the same folder claiming the same track number. */
final class RepeatedTrackNumbersCheck implements Check
{
    /** Structure: the numbering is what orders an album, so a collision is a shape fault. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Numbers and paths out of the database, compared in PHP. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** Music only — a book's chapters are checked for gaps rather than for collisions. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** Deliberately not "duplicate tracks": the FILES differ, only their numbers repeat. */
    public function title(): string
    {
        return 'Repeated track numbers';
    }

    /** What it means, and its relationship to the two sections either side of it. */
    public function blurb(): string
    {
        return 'One folder, two files claiming the same number — usually a bonus track that kept the number of the '
            .'track before it, or a hidden track numbered 0. It is the mirror of "Albums missing a track": the '
            .'numbering reaches short of the file count rather than past it, which is why that check refuses to '
            .'call this incomplete. If the colliding files were in DIFFERENT folders it would be two albums merged '
            .'into one row, which is the next section.';
    }

    /** The numbers first, then the one folder they are all in — which is what makes it this fault. */
    public function columns(): array
    {
        return ['Repeated', 'Folder'];
    }

    /**
     * The collisions that stay inside one directory.
     *
     * The complement of {@see MergedAlbumsCheck}: every collision is in exactly one of the two
     * sections, so no album is reported twice with two different cures.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $findings = array_map(
            fn (Collision $collision) => new AuditFinding(
                'collection:'.$collision->collectionId,
                $collision->name,
                [$collision->numbers->describe(), $collision->folders[0] ?? '—'],
            ),
            array_filter(
                $scope->collisions()->for(TrackType::Music),
                fn (Collision $collision) => ! $collision->spansFolders(),
            ),
        );

        return CheckFindings::of($findings);
    }
}
