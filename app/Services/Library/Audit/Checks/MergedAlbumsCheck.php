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

/** Two albums sharing one ALBUM tag, and therefore one row — an original and its remaster, typically. */
final class MergedAlbumsCheck implements Check
{
    /** Structure: two records share one row, which is as structural as it gets. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Tag and path facts from the database, compared against each other in PHP. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** Music only — books dedupe on title and have no ALBUM tag to collide. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** Says what the reader SEES — one row holding two albums — not how it was detected. */
    public function title(): string
    {
        return 'Two albums in one row';
    }

    /** The fault, and the two different cures the CAUSE column tells apart. */
    public function blurb(): string
    {
        return 'Two directories whose files carry the same ALBUM and ARTIST tags become ONE album row, so the album '
            .'page interleaves two rips and the track list has every number twice. The **cause** column says which '
            .'of two faults it is, because the cure is opposite. *Same ALBUM tag*: two different records — an '
            .'original beside its remaster, a standard edition beside a deluxe — so give one a distinguishing '
            .'ALBUM tag ("… (Remastered)"). *No DISC tags*: one genuine multi-disc set whose files were never disc-'
            .'numbered, so tag the discs and it stops colliding — renaming the album here would split a record '
            .'that belongs together. A properly disc-tagged set never appears at all, however its folders are '
            .'named: distinct DISC numbers are what stop its files colliding.';
    }

    /** The folders are the evidence, the numbers the proof, and the cause decides the cure. */
    public function columns(): array
    {
        return ['Folders', 'Repeated', 'Cause'];
    }

    /** The collisions that cross directories — the complement of {@see RepeatedTrackNumbersCheck}. */
    public function run(AuditScope $scope): CheckFindings
    {
        $findings = array_map(
            fn (Collision $collision) => new AuditFinding(
                'collection:'.$collision->collectionId,
                $collision->name,
                [
                    implode(' + ', $collision->folders),
                    $collision->numbers->describe(),
                    $collision->discTagged ? 'same ALBUM tag' : 'no DISC tags',
                ],
            ),
            array_filter(
                $scope->collisions()->for(TrackType::Music),
                fn (Collision $collision) => $collision->spansFolders(),
            ),
        );

        return CheckFindings::of($findings);
    }
}
