<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\ArtistFilter;
use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;

/** Artist names that read as several credits in one — a queue, because most of them are real names. */
final class LookalikeArtistNamesCheck implements Check
{
    /** A queue, and the longest one: most of what it lists is a real band name. */
    public function group(): AuditGroup
    {
        return AuditGroup::Queue;
    }

    /** The artists table alone, so as fresh as the last scan. */
    public function source(): AuditSource
    {
        return AuditSource::Database;
    }

    /** Music only — an artist is a music credit; a book's people are its author and narrator. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** "Look like" is the whole claim: it reports candidates, never faults. */
    public function title(): string
    {
        return 'Artist names that look like several';
    }

    /** Why this is a queue and how to triage it, since it is the longest section in a real library. */
    public function blurb(): string
    {
        return 'A name holding "feat.", "vs", "&", "/" or a comma is EITHER a real band name — Nick Cave & The Bad '
            .'Seeds, Earth, Wind & Fire — OR one file\'s guest credit that has become an artist of its own, '
            .'splitting a discography in two. Expect most of these to be real; this is the longest queue in a '
            .'well-kept library. The song count is the useful signal: a "lookalike" with one song beside an artist '
            .'with fifty is usually the fault, and the fix is to move the guest out of ARTIST and into the title.';
    }

    /** The song count, which is what makes a candidate triageable at a glance. */
    public function columns(): array
    {
        return ['Songs'];
    }

    /**
     * The listing's own predicate, borrowed for the same reason the album checks borrow theirs.
     *
     * The count is fetched with the rows (`withCount`) rather than per finding, so a hundred
     * candidates cost one query rather than a hundred and one.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $base = fn () => ArtistFilter::LookalikeName->apply(Artist::query(), null);

        $total = $base()->count();
        $rows = $base()
            ->withCount(['tracks' => fn ($tracks) => $tracks->where('tracks.type', TrackType::Music)])
            ->orderBy('artists.name')
            ->limit(CheckFindings::LIST_LIMIT)
            ->get();

        $listed = $rows->map(fn (Artist $artist) => new AuditFinding(
            'artist:'.$artist->id,
            (string) $artist->name,
            [number_format((int) $artist->getAttribute('tracks_count'))],
        ))->all();

        return CheckFindings::fromPage($total, $listed);
    }
}
