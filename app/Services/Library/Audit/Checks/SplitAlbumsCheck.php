<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;
use Illuminate\Support\Facades\DB;

/** One album folder whose files ended up in more than one album row. */
final class SplitAlbumsCheck implements Check
{
    /** Structure: one record arrives as two rows, which is a shape fault. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Paths and collection ids from the database, grouped by directory in PHP. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** Music only — a book has no ALBUM tag for one file to disagree about. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** Split, as the mirror of "Two albums in one row" — the same tags, the other way round. */
    public function title(): string
    {
        return 'Split albums';
    }

    /** The fault, how it looks in the app, and where the cause always is. */
    public function blurb(): string
    {
        return 'One directory, two album rows: a single file\'s ALBUM or ALBUM ARTIST tag differs from its '
            .'siblings\' — a truncated tag, "the" for "The", one track credited to a different composer. The album '
            .'then appears TWICE in the listing with its tracks divided between the halves, and each half looks '
            .'incomplete. The cause is always in the tags of the odd file out; the fix is to make it match, and '
            .'re-scan. This is the exact inverse of "Two albums in one row".';
    }

    /** The album rows the folder feeds, which is what shows the reader what disagrees. */
    public function columns(): array
    {
        return ['Rows'];
    }

    /**
     * Group the area's files by directory and keep the directories holding more than one album.
     *
     * IN PHP RATHER THAN IN SQL, and for a hard reason rather than a soft one: there is no
     * portable SQL spelling of `dirname` over a stored path — Postgres would want
     * `regexp_replace` and the test suite's sqlite has no regular expressions at all — so a
     * directory-grouped `having` clause cannot be written once for both. Two columns over the
     * area's tracks is a small read, and the grouping is a string split.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $rows = DB::table('tracks')
            ->join('collections', 'collections.id', '=', 'tracks.collection_id')
            ->select(['tracks.path', 'tracks.collection_id', 'collections.name'])
            ->where('tracks.type', TrackType::Music->value)
            ->whereNotNull('tracks.collection_id')
            ->orderBy('tracks.path')
            ->get();

        /** @var array<string, array<string, string>> $byFolder directory => collection id => album name */
        $byFolder = [];

        foreach ($rows as $row) {
            $byFolder[dirname((string) $row->path)][(string) $row->collection_id] = (string) $row->name;
        }

        $findings = [];

        foreach ($byFolder as $folder => $albums) {
            if (count($albums) < 2) {
                continue;
            }

            // Keyed on the FOLDER rather than on either album row, because the folder is the thing
            // that is wrong and the rows are what the next scan will replace.
            $findings[] = new AuditFinding('folder:'.$folder, $folder, [implode(' | ', array_values($albums))]);
        }

        return CheckFindings::of($findings);
    }
}
