<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Models\Track;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;
use Illuminate\Database\Eloquent\Builder;

/**
 * A check whose findings are individual FILES — most of the tag-hygiene block.
 *
 * A BASE CLASS, deliberately, and this is the axis that repeats: eleven checks differ only in
 * one `where` and their prose, and eleven copies of "count them, take the first fifty, turn each
 * into a finding keyed by id" would drift the moment the cap or the key changed. The seam is
 * declared abstract rather than defaulted, so a new check cannot inherit a predicate it never
 * meant to have.
 *
 * The subject is the PATH rather than the title, because the reader's next action is to open
 * that file in a tagger — a song called "Intro" names nothing they can act on.
 */
abstract class TrackCheck implements Check
{
    /** Read from the database, so as fresh as the last scan. Overridden by nothing here. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** Both areas by default: a missing year is a missing year whether it is a song or a chapter. */
    public function areas(): array
    {
        return TrackType::cases();
    }

    /** The path is the subject, so the one column left is what the file calls itself. */
    public function columns(): array
    {
        return ['Title'];
    }

    /**
     * The one predicate that makes this check itself.
     *
     * @param  Builder<Track>  $tracks  already scoped to the run's areas
     * @return Builder<Track> the same query, narrowed
     */
    abstract protected function constrain(Builder $tracks): Builder;

    /**
     * Count the matching files, then fetch the page the report can print.
     *
     * TWO QUERIES ON PURPOSE. A single `get()` would carry every row of a badly tagged library
     * into memory to print fifty of them, and the total is the number a reader acts on — "1,200
     * files have no genre" is a different message from the fifty names under it.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $areas = array_map(fn (TrackType $area) => $area->value, $scope->overlap($this->areas()));

        $base = fn () => $this->constrain(Track::query()->whereIn('tracks.type', $areas));

        $total = $base()->count();
        $rows = $base()
            ->select(['tracks.id', 'tracks.path', 'tracks.name'])
            ->orderBy('tracks.path')
            ->limit(CheckFindings::LIST_LIMIT)
            ->get();

        $listed = $rows->map(fn (Track $track) => new AuditFinding(
            'track:'.$track->id,
            $track->path,
            [$track->name],
        ))->all();

        return CheckFindings::fromPage($total, $listed);
    }
}
