<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\TrackType;
use Illuminate\Support\Facades\DB;

/**
 * Files in one collection sharing a `(disc, track)` number — ONE DETECTION WITH TWO DIAGNOSES.
 *
 * Two files that claim to be track 4 of disc 1 are either duplicate numbering inside one folder,
 * which is fixed by renumbering, or TWO ALBUMS WEARING ONE NAME, which is fixed by
 * distinguishing their ALBUM tags — an original and its remaster sharing a title is the shape it
 * takes in practice. The cure is different, the section is different, and the only thing that
 * tells them apart is whether the colliding files sit in the same directory.
 *
 * It matters that this is one class. Reported without the split, a merged album is eight
 * "repeated track number" rows for one problem and the wrong advice on every one of them; and
 * had the two checks each done their own grouping, they would have been free to disagree about
 * which albums collide at all.
 *
 * A THIRD DIAGNOSIS RIDES ALONG. A multi-disc set that was never disc-tagged sits in two folders
 * and collides on every track, exactly as a merged album does — so it reaches the same check, and
 * the cure is the opposite one (tag the discs, do not rename the album). What separates it is
 * whether ANY colliding file carries a disc number at all, which is why {@see Collision} records
 * that rather than leaving the reader to infer it from the numbers.
 *
 * THE DIRECTORY IS A GROUPING KEY AND NOTHING MORE. Nothing here parses a folder name: the first
 * draft of the merged-album check looked for `[Disc n]` and produced three false positives out
 * of four, because a real collection spells its disc folders `[Disc 1] The Coming Of The
 * Martians`, `Disc 1` and `[Disc 1]` in three different albums. Distinct disc NUMBERS are what
 * make a multi-disc set stop colliding, which is a fact in the tags rather than in the paths.
 */
final class TrackNumberCollisions
{
    /** @var array<string, Collision[]> memoised per area — both checks ask the same question. */
    private array $cache = [];

    /**
     * Every collection in an area holding a repeated `(disc, track)`, one entry per collection.
     *
     * @return Collision[]
     */
    public function for(TrackType $area): array
    {
        return $this->cache[$area->value] ??= $this->compute($area);
    }

    /**
     * Find the colliding numbers, then read the paths behind them.
     *
     * TWO QUERIES, and the grouping is done in SQL while the directory comparison is done in PHP.
     * That split is not a preference: `dirname` over a stored path has no portable SQL spelling —
     * Postgres would need `regexp_replace` and the test suite's sqlite has no regular expressions
     * at all — so a directory-grouped `having` clause could not be written once for both.
     *
     * @return Collision[]
     */
    private function compute(TrackType $area): array
    {
        $groups = DB::table('tracks')
            ->select(['collection_id', 'disc', 'track'])
            ->where('tracks.type', $area->value)
            ->whereNotNull('tracks.collection_id')
            ->whereNotNull('tracks.track')
            ->groupBy('tracks.collection_id', 'tracks.disc', 'tracks.track')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        // Keyed so the second query's rows can be matched back without a second grouping pass.
        $colliding = $groups
            ->mapWithKeys(fn (object $row) => [self::key($row->collection_id, $row->disc, $row->track) => true])
            ->all();

        $files = DB::table('tracks')
            ->join('collections', 'collections.id', '=', 'tracks.collection_id')
            ->select(['tracks.collection_id', 'tracks.disc', 'tracks.track', 'tracks.path', 'collections.name'])
            ->whereIn('tracks.collection_id', $groups->pluck('collection_id')->unique()->all())
            ->whereNotNull('tracks.track')
            ->orderBy('tracks.path')
            ->get();

        $byCollection = [];

        foreach ($files as $file) {
            $key = self::key($file->collection_id, $file->disc, $file->track);

            if (! isset($colliding[$key])) {
                continue;
            }

            $entry = $byCollection[$file->collection_id] ??= [
                'name' => (string) $file->name,
                'numbers' => new DiscTrackList,
                'folders' => [],
                'tagged' => false,
            ];

            $entry['numbers']->add($file->disc === null ? null : (int) $file->disc, (int) $file->track);
            $entry['folders'][dirname((string) $file->path)] = true;
            // ANY tagged file is enough: what the two checks need to know is whether the collision
            // could have been avoided by disc numbers that are simply absent.
            $entry['tagged'] = $entry['tagged'] || $file->disc !== null;
            $byCollection[$file->collection_id] = $entry;
        }

        $collisions = [];

        foreach ($byCollection as $id => $entry) {
            $collisions[] = new Collision(
                (string) $id,
                $entry['name'],
                $entry['numbers'],
                array_keys($entry['folders']),
                $entry['tagged'],
            );
        }

        // By name, so the report is stable enough to compare against the last one it wrote.
        usort($collisions, fn (Collision $a, Collision $b) => strcasecmp($a->name, $b->name));

        return $collisions;
    }

    /** A collection's disc/track pair as one comparable string — nulls included, since no disc is a value. */
    private static function key(mixed $collectionId, mixed $disc, mixed $track): string
    {
        return $collectionId.'|'.($disc ?? '-').'|'.$track;
    }
}
