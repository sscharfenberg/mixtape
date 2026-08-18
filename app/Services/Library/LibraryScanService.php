<?php

namespace App\Services\Library;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection as MediaCollection;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Play;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Services\Library\Contracts\TagReader;
use App\Services\Media\CoverService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The library scanner — a content-hash *diff* rather than a truncate-and-rebuild
 * (data-model.md → "The one fact that colours everything").
 *
 * Per area, inside one transaction:
 *   1. Enumerate the audio files on disk.
 *   2. Pass 1 — match each file to an existing row by `path`:
 *        · path + size + mtime unchanged  → fast-path, keep the row untouched
 *          (no hashing — this is what keeps steady-state scans fast);
 *        · path matches, bytes changed    → a re-tag: re-read + UPDATE in place,
 *          id kept.
 *   3. Pass 2 — files whose path is new: hash the audio and look among the rows
 *      whose old path vanished this scan (rename candidates):
 *        · exactly one same-hash candidate → a rename/move: update path, id kept;
 *        · several                          → disambiguate on directory/filename;
 *        · none                             → genuinely new audio: INSERT.
 *   4. Orphans — rows whose file is gone → relink-then-cascade → hard delete.
 *   5. Prune orphaned taxonomy + empty collections the diff left behind.
 *   6. Record each surviving container's cover image as a path (see
 *      syncCollectionCovers) — the step that keeps cover lookup off the
 *      filesystem at request time.
 *
 * Identity is the audio-frame hash, so a rename OR a re-tag keeps the row's id —
 * the guarantee every downstream feature (playlists, most-played, share links)
 * relies on. Two files with identical audio are two rows (clones) sharing a hash.
 */
final class LibraryScanService
{
    /**
     * The tag reader is injected (not `new`ed) so the scan tests can swap getID3 for a
     * fake reader. CoverService comes in for one method — the directory-image
     * resolution the cover routes also use — so the candidate-name rules have exactly
     * one implementation, whether they run per scan or (as a stale-path fallback) per
     * request.
     */
    public function __construct(
        private readonly TagReader $reader,
        private readonly CoverService $covers,
    ) {}

    /**
     * @param  TrackType[]  $areas  areas to scan
     * @param  (Closure(string):void)|null  $progress  headline milestones for the command to narrate
     * @param  bool  $recheckYears  re-read EVERY container's files to reconcile its year, instead
     *                              of only the containers this run touched — see
     *                              {@see syncCollectionYears} for the discrepancy an ordinary
     *                              scan cannot see
     */
    public function scan(array $areas, ?Closure $progress = null, bool $recheckYears = false): ScanSummary
    {
        $results = [];

        foreach ($areas as $type) {
            $results[$type->value] = $this->scanArea($type, $progress, $recheckYears);
        }

        return new ScanSummary($results);
    }

    /**
     * Reconcile one area against the database — the per-area body of the diff laid
     * out in the class docblock. Resolves and guards the configured root (an unused
     * area is skipped; a missing directory aborts; a suddenly-empty directory that
     * still has rows is protected, never pruned), then runs the two passes + orphan
     * cleanup inside a single transaction so a mid-scan crash can't leave the area
     * half-migrated. Returns the per-area tally.
     */
    private function scanArea(TrackType $type, ?Closure $progress, bool $recheckYears = false): ScanResult
    {
        $result = new ScanResult($type);

        $root = trim((string) config('mixtape.library.paths.'.$type->libraryPathKey()));

        // An unconfigured (empty) path means this area simply isn't in use on
        // this instance — skip it, touching no rows. A collection with no audiobooks
        // is the ordinary case. This is NOT the same as a configured path that has
        // gone missing (below).
        if ($root === '') {
            $this->announce($progress, "{$type->value}: not configured — skipped");

            return $result;
        }

        // A configured path that isn't a directory is a structural failure (a
        // typo, or a dropped mount) — abort so the command e-mails an alert,
        // rather than "finding zero files" and orphan-deleting the whole area.
        if (! is_dir($root)) {
            throw new RuntimeException("Library path for {$type->value} not found: {$root}");
        }

        $files = $this->enumerate($root);
        $result->discovered = count($files);
        $this->announce($progress, "{$type->value}: found {$result->discovered} file(s)");

        // Safety guard: a configured, existing directory that suddenly yields zero files is far
        // more likely a mount/permission problem than a real mass-deletion. Refuse
        // to prune — leave every row intact — so a dropped share can't wipe an
        // area, and flag it so the command alerts. (A genuine full-clear is rare;
        // do it deliberately, not via a scan that found nothing.) With no existing
        // rows there's nothing to protect, so the normal path runs and does nothing.
        $existingRows = Track::query()->where('type', $type)->count();
        if ($result->discovered === 0 && $existingRows > 0) {
            $result->skippedEmpty = true;
            $result->protectedRows = $existingRows;
            $this->announce($progress, "{$type->value}: found 0 files but {$existingRows} row(s) exist — skipped (no pruning; likely a mount problem)");
            Log::channel('library')->warning("scan: {$type->value} yielded 0 files but {$existingRows} rows exist — skipped to avoid wiping the area");

            return $result;
        }

        DB::transaction(function () use ($type, $files, $result, $root, $recheckYears) {
            /** @var Collection<string, Track> $existing keyed by area-relative path */
            $existing = Track::query()->where('type', $type)->get()->keyBy('path');

            /*
             * The year each file we actually READ this run claims, per container:
             * [collection id => [area-relative path => year|null]].
             *
             * Gathered here rather than queried afterwards because a track row does not store
             * its own year — a year is a fact about a release, so it lives on the collection
             * (data-model.md) — which means the only place a FILE's year exists is the metadata
             * the reader just returned. syncCollectionYears reconciles it after the diff.
             */
            $yearsSeen = [];

            $claimed = []; // [track id => true] — rows matched to a file this scan
            $newFiles = []; // files whose path isn't in the DB → pass 2

            // --- Pass 1: match by path (fast-path + same-path re-tag) ---------
            // `path` is stored RELATIVE to the area root, so moving the collection
            // (changing the configured root) still matches here — the read uses
            // the absolute path, only the stored/keyed value is relative.
            foreach ($files as $file) {
                $absPath = $file->getPathname();
                $relPath = $this->relativePath($root, $absPath);
                $size = (int) $file->getSize();
                $mtime = (int) $file->getMTime();

                $row = $existing->get($relPath);

                if ($row === null) {
                    $newFiles[] = [$absPath, $relPath, $size, $mtime];

                    continue;
                }

                $claimed[$row->getKey()] = true;

                if ($this->unchanged($row, $size, $mtime)) {
                    continue; // fast-path — untouched, no hashing
                }

                // Same path, changed bytes → a re-tag. Re-read and update in place.
                $meta = $this->readOrSkip($absPath, $result);
                if ($meta === null) {
                    continue;
                }

                $attributes = $this->buildAttributes($type, $meta, $relPath, $size, $mtime);
                $this->noteYear($yearsSeen, $attributes, $relPath, $meta);
                $row->fill($attributes)->save();
                $result->updated++;

                // The file's bytes changed while its id did not — identity is the hash of
                // the audio frames alone — which is the ONE case the cover cache cannot notice
                // on its own: a re-tag that replaced the embedded picture would keep
                // being served from the old cached JPEG. Dropped here because this is
                // the exact moment we know it happened.
                if ($this->covers->forget($row)) {
                    $result->coversForgotten++;
                }
            }

            // Rename candidates = rows not claimed in pass 1, bucketed by hash.
            $byHash = $existing
                ->reject(fn (Track $t) => isset($claimed[$t->getKey()]))
                ->groupBy('content_hash');

            // --- Pass 2: new paths — hash, rename-match, else insert ----------
            foreach ($newFiles as [$absPath, $relPath, $size, $mtime]) {
                $meta = $this->readOrSkip($absPath, $result);
                if ($meta === null) {
                    continue;
                }

                $candidates = ($byHash->get($meta->contentHash) ?? collect())
                    ->reject(fn (Track $t) => isset($claimed[$t->getKey()]));

                $match = $this->pickRenameCandidate($candidates, $relPath);

                $attributes = $this->buildAttributes($type, $meta, $relPath, $size, $mtime);
                $this->noteYear($yearsSeen, $attributes, $relPath, $meta);

                if ($match !== null) {
                    $match->fill($attributes)->save();
                    $claimed[$match->getKey()] = true;
                    $result->renamed++;
                } else {
                    Track::create($attributes);
                    $result->inserted++;
                }
            }

            // --- Orphans: file gone → relink-then-cascade → delete ------------
            foreach ($existing as $row) {
                if (isset($claimed[$row->getKey()])) {
                    continue;
                }

                $this->relinkThenDelete($row);
                $result->deleted++;
            }

            // --- Prune taxonomy/collections the diff orphaned -----------------
            $this->pruneOrphans($type);

            // --- Record each container's cover image --------------------------
            // After the prune, so the collections still standing are the real ones.
            $result->covers = $this->syncCollectionCovers($type);

            // --- Reconcile each container's year against its files' tags -------
            // Also after the prune, and for the same reason.
            $result->years = $this->syncCollectionYears($type, $root, $yearsSeen, $recheckYears);
        });

        $this->announce($progress, sprintf(
            '%s: %d new, %d changed, %d moved, %d removed, %d skipped, %d cover(s) recorded, %d year(s) corrected',
            $type->value, $result->inserted, $result->updated, $result->renamed, $result->deleted, $result->errors,
            $result->covers, $result->years
        ));

        return $result;
    }

    /**
     * All audio files under a root, matched case-insensitively on the configured
     * extensions. Dotfiles are ignored (real junk is removed by the cleanup step
     * first); symlinks are followed, as the shares may link across mounts.
     *
     * @return SplFileInfo[]
     */
    private function enumerate(string $root): array
    {
        $extensions = (array) config('mixtape.scan.extensions', ['mp3']);
        $pattern = '/\.('.implode('|', array_map(fn ($e) => preg_quote((string) $e, '/'), $extensions)).')$/i';

        $finder = (new Finder)
            ->files()
            ->in($root)
            ->ignoreDotFiles(true)
            ->followLinks()
            ->name($pattern);

        return iterator_to_array($finder, false);
    }

    /**
     * The path of a file relative to its area root — what gets stored, so the
     * DB never bakes in the configured location and moving the collection is a
     * fast-path no-op. Finder guarantees the file is under $root; the fallback
     * (strip a leading slash) only guards a theoretical mismatch.
     */
    private function relativePath(string $root, string $absolute): string
    {
        $prefix = rtrim($root, '/').'/';

        return str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : ltrim($absolute, '/');
    }

    /** The steady-state fast-path: same path, same size, same mtime → untouched. */
    private function unchanged(Track $row, int $size, int $mtime): bool
    {
        return $row->size !== null
            && $row->modified_at !== null
            && (int) $row->size === $size
            && $row->modified_at->getTimestamp() === $mtime;
    }

    /** Read metadata, or record a non-fatal skip and return null so the scan continues. */
    private function readOrSkip(string $path, ScanResult $result): ?TrackMetadata
    {
        try {
            return $this->reader->read($path);
        } catch (\Throwable $e) {
            $result->errors++;
            $result->skipped[] = ['path' => $path, 'reason' => $e->getMessage()];
            Log::channel('library')->warning("scan: skipped file {$path} — {$e->getMessage()}");

            return null;
        }
    }

    /**
     * The full attribute set for a track, resolving taxonomy + collection per
     * area. Used for both INSERT and UPDATE, so a re-tag re-points FKs correctly
     * (and any taxonomy it abandons is swept up by pruneOrphans afterwards).
     *
     * @param  string  $relativePath  path relative to the area root (what we store)
     * @return array<string, mixed>
     */
    private function buildAttributes(TrackType $type, TrackMetadata $meta, string $relativePath, int $size, int $mtime): array
    {
        $attributes = [
            'type' => $type,
            // Fall back to the filename when a file carries no title tag, so a
            // track is never nameless in the UI.
            'name' => $meta->title ?? pathinfo($relativePath, PATHINFO_FILENAME),
            'path' => $relativePath,
            'content_hash' => $meta->contentHash,
            'size' => $size,
            'modified_at' => Carbon::createFromTimestamp($mtime),
            'codec' => $meta->codec,
            'channel' => $meta->channel,
            'duration' => $meta->duration,
            'sample_rate' => $meta->sampleRate,
            'bit_rate' => $meta->bitRate,
            'vbr' => $meta->vbr,
            'cover' => $meta->hasCover,
            'track' => $meta->track,
            'disc' => $meta->disc,
            // Taxonomy filled per type below; the tracks CHECK constraint pins
            // which FKs may be set for which type.
            'collection_id' => null,
            'artist_id' => null,
            'genre_id' => null,
            'narrator_id' => null,
            'author_id' => null,
            'composer' => null,
            'publisher' => null,
        ];

        switch ($type) {
            case TrackType::Music:
                $artist = $this->taxonomy(Artist::class, $meta->artist);
                $albumArtist = $this->taxonomy(Artist::class, $meta->albumArtist ?? $meta->artist);
                $genre = $this->taxonomy(Genre::class, $meta->genre);
                $collection = $this->collection(CollectionType::Album, $meta->album, ['album_artist_id' => $albumArtist?->id], $meta->year);

                $attributes['artist_id'] = $artist?->id;
                $attributes['genre_id'] = $genre?->id;
                $attributes['composer'] = $meta->composer;
                $attributes['publisher'] = $meta->publisher;
                $attributes['collection_id'] = $collection?->id;
                break;

            case TrackType::Audiobook:
                // Legacy remapping: composer (TCOM) → author, artist (TPE1) → narrator.
                //
                // BOTH LAND ON THE CHAPTER, and the book is keyed on its title alone. TCOM is
                // a per-file tag and an anthology uses it per story: "Necrophobia 1" names
                // six authors across its 32 chapters. With the author in the collection key,
                // that book scans as six rows sharing a name — measured, on a real library.
                // So the author is a fact about the chapter, exactly as the narrator is.
                $author = $this->taxonomy(Author::class, $meta->composer);
                $narrator = $this->taxonomy(Narrator::class, $meta->artist);
                $collection = $this->collection(CollectionType::Audiobook, $meta->album, [], $meta->year);

                $attributes['author_id'] = $author?->id;
                $attributes['narrator_id'] = $narrator?->id;
                $attributes['collection_id'] = $collection?->id;
                break;
        }

        return $attributes;
    }

    /**
     * firstOrCreate a taxonomy row by name — case-insensitively, via the column's own
     * collation. Blank/absent tag → no row.
     *
     * @param  class-string<Model>  $model
     */
    private function taxonomy(string $model, ?string $name): ?object
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }

        $row = $model::firstOrCreate(['name' => $name]);
        $this->adoptSpelling($row, $name);

        return $row;
    }

    /**
     * Rewrite a found row's name when the tag spells it differently — which, the lookup
     * being case-insensitive, means A CHANGE OF CASE.
     *
     * WITHOUT THIS, RENAMING AN ARTIST DOES NOTHING: re-tag "NARGAROTH" to "Nargaroth", run
     * `app:update`, and the app goes on saying NARGAROTH. The dedup that makes "Rock" and
     * "rock" one genre is a column collation, so
     * `firstOrCreate(['name' => 'Nargaroth'])` FINDS the all-caps row and hands it back
     * unchanged — no insert, no update, nothing to notice. Every other kind of rename works,
     * because a genuinely different name misses the lookup, mints a row and leaves the old one
     * to be pruned; only the case-only edit falls through the gap between those two paths.
     *
     * THE TAGS ARE THE SOURCE OF TRUTH, so the incoming spelling wins. The cost is
     * last-writer-wins where two files disagree — "NARGAROTH" on one and "Nargaroth" on
     * another leaves the row on whichever was read last. That is a tagging inconsistency
     * rather than a rule to invent around, and it stays narrow in practice because this only
     * runs for files the scan actually re-read: an untouched all-caps file elsewhere in the
     * library does not fight the rename on the next scan.
     *
     * Through Eloquent rather than the query builder, so the HasFoldedName mutator refreshes
     * `name_fold` with it — a stale fold is a silent search miss.
     */
    private function adoptSpelling(Model $row, string $name): void
    {
        if ($row->name === $name) {
            return;
        }

        $row->update(['name' => $name]);
    }

    /**
     * Remember what year one file claimed, against the container it was filed under.
     *
     * @param  array<string, array<string, int|null>>  $seen  [collection id => [path => year]]
     * @param  array<string, mixed>  $attributes  the track attributes just built (for its collection)
     */
    private function noteYear(array &$seen, array $attributes, string $relativePath, TrackMetadata $meta): void
    {
        $collectionId = $attributes['collection_id'] ?? null;

        if ($collectionId === null) {
            return;
        }

        $seen[$collectionId][$relativePath] = $meta->year;
    }

    /**
     * Bring each touched container's `year` back in line with what its files' tags say.
     *
     * WHY THIS EXISTS. A collection's year is written when the row is created and was never
     * revisited, so correcting a mis-tagged album's year did nothing that a reader could see:
     * the files said 1992, the page said 1982, and the only cure was deleting the row and
     * rescanning. Every other tag already follows an edit — a re-tagged file's row is rebuilt
     * from `buildAttributes` — which made the year the one fact in the library that could not be
     * corrected by correcting the source.
     *
     * IT TAKES UNANIMITY, NEVER A LAST WRITE. The rule the old comment defended is still the
     * right one: one mis-tagged file must not be able to re-date a record. So a year moves only
     * when EVERY file of the container agrees on the same one, which also makes the outcome
     * independent of the order the walk happened to read them in. Where the files disagree, the
     * stored year stays and the disagreement is the reader's to fix — guessing a winner would be
     * inventing a fact about a release out of a tagging mistake.
     *
     * UNANIMITY IS OVER THE WHOLE CONTAINER, not over the files this run read, and that is what
     * the extra reads below are for. An incremental scan sees only what changed, so correcting
     * one file of a fifteen-track album shows this method a single year — enough to contradict
     * the stored one, never enough to decide. When the files it saw DO contradict the stored
     * year, it reads the rest of the container's files before committing; when they agree with
     * it, there is nothing to decide and nothing is read. So the common rescan costs no I/O at
     * all, and the correcting one costs the album it corrects.
     *
     * WHICH LEAVES ONE CASE AN ORDINARY SCAN CANNOT SEE, and `$recheckAll` is for it: a
     * discrepancy that ALREADY exists. A row created years ago from tags that have since been
     * corrected disagrees with every one of its files, and no file has changed since — so nothing
     * is read, nothing contradicts anything, and the wrong year survives every rescan. Measured
     * on the dev library: *Check Your Head* stored 1982 while all fifteen files said 1992.
     * `--recheck-years` drops the early bail-out and reads every container's files, at the price
     * of reading the whole library's tags once. It is a flag rather than the default because that
     * is the difference between a scan that stats 12,000 files and one that parses them.
     *
     * A NULL YEAR IS A VALUE, both ways round: tags that no longer carry a year clear it, because
     * the tags are the source of truth and a stale year nobody can remove is the bug this fixes
     * pointing the other way.
     *
     * @param  string  $root  the area root, for turning a stored relative path back into a file
     * @param  array<string, array<string, int|null>>  $seen  [collection id => [path => year]]
     * @param  bool  $recheckAll  ask every container of this area, not just the touched ones
     * @return int containers whose year changed
     */
    private function syncCollectionYears(TrackType $type, string $root, array $seen, bool $recheckAll = false): int
    {
        $corrected = 0;

        // One query for every container in play rather than a find() per id: a first scan touches
        // all of them, and 900-odd point lookups to establish that nothing needs correcting is a
        // cost the steady-state case should not pay either.
        $collections = $recheckAll
            ? MediaCollection::query()->where('type', $type->collectionType())->get()
            : MediaCollection::query()->findMany(array_keys($seen));

        foreach ($collections as $collection) {
            $years = $seen[$collection->getKey()] ?? [];

            if (! $recheckAll) {
                $candidate = $this->unanimousYear($years);

                // The files we read disagree among themselves, or they already say what the row
                // says. Either way there is nothing to decide and no reason to read anything.
                if ($candidate === false || $candidate === $collection->year) {
                    continue;
                }
            }

            $unseen = Track::query()
                ->where('collection_id', $collection->getKey())
                ->whereNotIn('path', array_keys($years))
                ->pluck('path');

            foreach ($unseen as $path) {
                try {
                    $years[$path] = $this->reader->read(rtrim($root, '/').'/'.$path)->year;
                } catch (\Throwable $e) {
                    // A file we cannot read is a file that cannot vote, and one silent voice is
                    // enough to make unanimity a guess — so the container is left alone. Logged
                    // rather than counted as a scan error: nothing about the TRACK failed here.
                    Log::channel('library')->warning("scan: year check skipped {$path} — {$e->getMessage()}");
                    $years = [];

                    break;
                }
            }

            $agreed = $this->unanimousYear($years);

            if ($agreed === false || $agreed === $collection->year) {
                continue;
            }

            $collection->update(['year' => $agreed]);
            $corrected++;
        }

        return $corrected;
    }

    /**
     * The one year a set of files agrees on, or `false` where they do not.
     *
     * `false` for "no agreement" rather than null, because NULL is itself an answer — a
     * container whose files carry no year at all agrees on having none.
     *
     * @param  array<string, int|null>  $years
     */
    private function unanimousYear(array $years): int|null|false
    {
        if ($years === []) {
            return false;
        }

        $distinct = array_unique($years, SORT_REGULAR);

        return count($distinct) === 1 ? reset($distinct) : false;
    }

    /**
     * firstOrCreate a collection, keyed on (type, name, owner) — the same tuple
     * the DB dedup index enforces.
     *
     * `year` is written only HERE, on creation, because a single file is the wrong voice to date
     * a release with: the file being read is whichever one the walk reached first, so letting it
     * write would make an album's year depend on directory order and let one typo re-date a
     * record. Keeping the year current is {@see syncCollectionYears}'s job instead, after the
     * diff, where the whole container can be asked at once.
     *
     * @param  array{album_artist_id?: ?string}  $owner  empty for an audiobook, which has no
     *                                                   owner column — its author is per chapter
     */
    private function collection(CollectionType $type, ?string $name, array $owner, ?int $year): ?MediaCollection
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }

        $collection = MediaCollection::firstOrCreate(
            [
                'type' => $type,
                'name' => $name,
                'album_artist_id' => $owner['album_artist_id'] ?? null,
            ],
            ['year' => $year],
        );

        // An album's name is deduped case-insensitively too, so a re-cased album title is
        // invisible here for exactly the reason it was invisible for an artist — see
        // adoptSpelling. Renaming in place cannot collide: any row the new spelling could clash
        // with is the row the lookup just returned.
        $this->adoptSpelling($collection, $name);

        return $collection;
    }

    /**
     * Choose which unclaimed same-hash row an incoming new path is a rename of.
     * One candidate is unambiguous. Several means duplicate audio moved this scan
     * — prefer a match on parent directory, then filename; failing both, pick any
     * (the audio is identical, so an id swap between clones is invisible unless a
     * playlist pinned one specifically — data-model.md's accepted "known limit").
     *
     * @param  Collection<int, Track>  $candidates
     */
    private function pickRenameCandidate(Collection $candidates, string $path): ?Track
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $dir = basename(dirname($path));
        $base = pathinfo($path, PATHINFO_BASENAME);

        return $candidates->first(fn (Track $t) => basename(dirname($t->path)) === $dir)
            ?? $candidates->first(fn (Track $t) => pathinfo($t->path, PATHINFO_BASENAME) === $base)
            ?? $candidates->first();
    }

    /**
     * Before hard-deleting an orphaned track, repoint its playlist entries and
     * plays to a surviving clone (another row with the same audio) so a curated
     * playlist survives culling one of two identical files. With no clone, the FK
     * `cascade` drops them when the row is deleted (data-model.md → "Foreign keys").
     */
    private function relinkThenDelete(Track $row): void
    {
        $survivor = Track::query()
            ->where('content_hash', $row->content_hash)
            ->whereKeyNot($row->getKey())
            ->first();

        if ($survivor !== null) {
            PlaylistTrack::query()->where('track_id', $row->getKey())->update(['track_id' => $survivor->getKey()]);
            Play::query()->where('track_id', $row->getKey())->update(['track_id' => $survivor->getKey()]);
        }

        // The row is going, so its cached cover is dead weight — nothing will ever ask
        // for that id again. Safe even when a clone survives: the cache is keyed by
        // ROW id, so the survivor has (or will extract) its own entry.
        $this->covers->forget($row);

        $row->delete();
    }

    /**
     * Delete taxonomy/collections the diff left with no referrers. A diff (unlike
     * truncate) leaves these behind, and a browse list full of zero-track artists
     * is bad UX (data-model.md → "Foreign keys"). Order matters: empty collections first,
     * then the contributors they referenced — a `restrict` FK would otherwise
     * block deleting a still-referenced artist.
     */
    private function pruneOrphans(TrackType $type): void
    {
        MediaCollection::query()
            ->where('type', $type->collectionType())
            ->whereDoesntHave('tracks')
            ->delete();

        switch ($type) {
            case TrackType::Music:
                Genre::query()->whereDoesntHave('tracks')->delete();
                // An artist is reachable as a performer (tracks) AND as an
                // album-artist (collections) — both must be empty to prune.
                Artist::query()->whereDoesntHave('tracks')->whereDoesntHave('albums')->delete();
                break;

            case TrackType::Audiobook:
                // Both are reachable only through tracks now that the author sits on the
                // chapter, so the two read identically — an author with no chapter left
                // anywhere is an orphan, even if a book they contributed to survives.
                Narrator::query()->whereDoesntHave('tracks')->delete();
                Author::query()->whereDoesntHave('tracks')->delete();
                break;
        }
    }

    /**
     * Record each container's cover image, and return how many rows changed.
     *
     * This is the scan step that exists so a page render never has to touch the
     * filesystem: `collections.cover_path` holds the area-relative path of the
     * directory image, resolved by the one implementation the request side also uses
     * (CoverService::directoryImage — candidate names in configured order, matched
     * case-insensitively, then a lone unrecognised image). Nothing is extracted or
     * written; the column holds a filename, so the cost is one directory read per
     * album rather than 12060 image decodes.
     *
     * The directory comes from each container's FIRST track by `(disc, track, name)`,
     * which is deliberately the same rule CoverService applies when it has to resolve
     * live: a multi-disc set whose discs sit in subdirectories then resolves to disc
     * 1's, where a ripper puts the album art. One query gets every container plus that
     * path — a correlated subselect, not a query per row — so the whole step costs one
     * SELECT and one directory read per container (923 of each on the live collection),
     * against the 12060 files the scan has already stat'ed by this point.
     *
     * Only rows whose value actually CHANGED are written, so a steady-state rescan of
     * a 923-album collection issues no UPDATEs at all — matching the fast-path
     * philosophy of pass 1.
     */
    private function syncCollectionCovers(TrackType $type): int
    {
        $containers = MediaCollection::query()
            ->where('type', $type->collectionType())
            ->select(['id', 'cover_path'])
            ->addSelect(['sample_path' => Track::query()
                ->select('path')
                ->whereColumn('tracks.collection_id', 'collections.id')
                ->orderBy('disc')
                ->orderBy('track')
                ->orderBy('name')
                ->limit(1),
            ])
            ->get();

        $root = $this->areaRoot($type);
        $seen = []; // absolute directory → resolved image path (or null), per scan
        $changed = 0;

        foreach ($containers as $container) {
            $resolved = null;

            if ($container->sample_path !== null) {
                $absolute = Track::absolutePathFor($container->sample_path, $type);
                $directory = dirname($absolute);

                // Memoised because two containers CAN share a directory — a folder
                // holding tracks tagged with two different album names, which is what a
                // mis-tagged compilation looks like on disk. `array_key_exists` rather
                // than `??=`, since "no image here" memoises as NULL and `??=` would
                // read that directory again for the second album.
                if (! array_key_exists($directory, $seen)) {
                    $seen[$directory] = $this->covers->directoryImage($absolute);
                }

                // Stored area-relative, like every other path here, so moving the
                // collection to another root doesn't invalidate the column.
                $resolved = $seen[$directory] === null
                    ? null
                    : $this->relativePath($root, $seen[$directory]);
            }

            if ($container->cover_path !== $resolved) {
                $container->update(['cover_path' => $resolved]);
                $changed++;

                // The album's art is a different file now (or gone), so every scaled
                // copy cached under its id is stale — including the mtime variants no
                // key will ever match again. The next request rebuilds exactly one.
                $this->covers->forgetAlbum($container);
            }
        }

        return $changed;
    }

    /** The configured, trailing-slash-trimmed root of an area — the prefix a stored path hangs off. */
    private function areaRoot(TrackType $type): string
    {
        return rtrim(trim((string) config('mixtape.library.paths.'.$type->libraryPathKey())), '/');
    }

    /** Forward a milestone line to the optional progress callback — a no-op when the caller (e.g. a test) passed none. */
    private function announce(?Closure $progress, string $line): void
    {
        if ($progress !== null) {
            $progress($line);
        }
    }
}
