<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditSource;
use App\Models\Collection;
use App\Models\Track;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;
use Illuminate\Database\Eloquent\Builder;

/**
 * A check whose findings are ALBUMS or BOOKS, the other half of the repeating axis.
 *
 * It carries one thing {@see TrackCheck} does not: every finding names the DIRECTORY a
 * representative file sits in. An album's name is not enough to act on — this library holds
 * several "Greatest Hits" and two albums called the same thing by the same band, which is one
 * of the faults being reported — and the folder is where the reader's tagger has to go. It comes
 * from a correlated subquery rather than a relation, so listing fifty albums is one query rather
 * than fifty-one.
 */
abstract class CollectionCheck implements Check
{
    /** Read from the database, so as fresh as the last scan. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** The name is the subject; the folder is what makes it findable. */
    public function columns(): array
    {
        return ['Folder'];
    }

    /**
     * The one predicate that makes this check itself.
     *
     * @param  Builder<Collection>  $collections  already scoped to this check's container kind
     * @return Builder<Collection> the same query, narrowed
     */
    abstract protected function constrain(Builder $collections): Builder;

    /**
     * Count, then fetch the printable page — see {@see TrackCheck::run} for why it is two queries.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        // ONE AREA PER CHECK at this grain, and that is why the container kind can be read off
        // the first: "an album missing a track" and "a book missing a chapter" are two entries in
        // the registry over one predicate, not one check that spans both. The orchestrator has
        // already skipped this check if its area is outside the run.
        $type = $this->areas()[0]->collectionType();

        $base = fn () => $this->constrain(Collection::query()->where('collections.type', $type));

        $total = $base()->count();
        $rows = $base()
            ->select(['collections.id', 'collections.name'])
            // The album's first file by the same rule the covers and the queue use, so the folder
            // named here is the one a reader opening the album would land in.
            ->addSelect(['sample_path' => Track::query()
                ->select('tracks.path')
                ->whereColumn('tracks.collection_id', 'collections.id')
                ->orderBy('tracks.disc')
                ->orderBy('tracks.track')
                ->orderBy('tracks.name')
                ->limit(1),
            ])
            ->orderBy('collections.name')
            ->limit(CheckFindings::LIST_LIMIT)
            ->get();

        $listed = $rows->map(fn (Collection $row) => new AuditFinding(
            'collection:'.$row->id,
            (string) $row->name,
            [self::folderOf($row->getAttribute('sample_path'))],
        ))->all();

        return CheckFindings::fromPage($total, $listed);
    }

    /**
     * The directory a sample file sits in, area-relative.
     *
     * `dirname` on a string rather than anything cleverer: a directory here is a GROUPING KEY and
     * nothing more. Parsing folder names for meaning is what produced three false positives out
     * of four in the merged-album check's first draft, and the rule is worth keeping even where
     * the only use is a table cell.
     */
    private static function folderOf(mixed $samplePath): string
    {
        if (! is_string($samplePath) || $samplePath === '') {
            return '—';
        }

        $directory = dirname($samplePath);

        return $directory === '.' ? '/' : $directory;
    }
}
