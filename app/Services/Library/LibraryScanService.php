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
 * The library scanner — a content-hash *diff*, not the legacy truncate-and-
 * rebuild (data-model.md → "the one fact that colours everything").
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
 *
 * Identity is the audio-frame hash, so a rename OR a re-tag keeps the row's id —
 * the guarantee every downstream feature (playlists, most-played, share links)
 * relies on. Two files with identical audio are two rows (clones) sharing a hash.
 */
final class LibraryScanService
{
    public function __construct(private readonly TagReader $reader) {}

    /**
     * @param  TrackType[]  $areas  areas to scan
     * @param  (Closure(string):void)|null  $progress  headline milestones for the command to narrate
     */
    public function scan(array $areas, ?Closure $progress = null): ScanSummary
    {
        $results = [];

        foreach ($areas as $type) {
            $results[$type->value] = $this->scanArea($type, $progress);
        }

        return new ScanSummary($results);
    }

    private function scanArea(TrackType $type, ?Closure $progress): ScanResult
    {
        $result = new ScanResult($type);

        $root = trim((string) config('mixtape.library.paths.'.$type->libraryPathKey()));

        // An unconfigured (empty) path means this area simply isn't in use on
        // this instance — skip it, touching no rows. Common for podcast_shows,
        // which many collections won't have. This is NOT the same as a configured
        // path that has gone missing (below).
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

        // Safety guard (ported from the legacy "empty list → don't touch the DB"):
        // a configured, existing directory that suddenly yields zero files is far
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

        DB::transaction(function () use ($type, $files, $result, $root) {
            /** @var Collection<string, Track> $existing keyed by area-relative path */
            $existing = Track::query()->where('type', $type)->get()->keyBy('path');

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

                $row->fill($this->buildAttributes($type, $meta, $relPath, $size, $mtime))->save();
                $result->updated++;
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

                if ($match !== null) {
                    $match->fill($this->buildAttributes($type, $meta, $relPath, $size, $mtime))->save();
                    $claimed[$match->getKey()] = true;
                    $result->renamed++;
                } else {
                    Track::create($this->buildAttributes($type, $meta, $relPath, $size, $mtime));
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
        });

        $this->announce($progress, sprintf(
            '%s: %d new, %d changed, %d moved, %d removed, %d skipped',
            $type->value, $result->inserted, $result->updated, $result->renamed, $result->deleted, $result->errors
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
                $author = $this->taxonomy(Author::class, $meta->composer);
                $narrator = $this->taxonomy(Narrator::class, $meta->artist);
                $collection = $this->collection(CollectionType::Audiobook, $meta->album, ['author_id' => $author?->id], $meta->year);

                $attributes['narrator_id'] = $narrator?->id;
                $attributes['collection_id'] = $collection?->id;
                break;

            case TrackType::Podcast:
                // No legacy reference — minimal for now: episode under a show, no
                // contributor taxonomy (podcast tracks are unconstrained by the CHECK).
                $collection = $this->collection(CollectionType::PodcastShow, $meta->album, [], $meta->year);
                $attributes['collection_id'] = $collection?->id;
                break;
        }

        return $attributes;
    }

    /**
     * firstOrCreate a taxonomy row by name (case-insensitive on Postgres via the
     * column collation). Blank/absent tag → no row.
     *
     * @param  class-string<Model>  $model
     */
    private function taxonomy(string $model, ?string $name): ?object
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }

        return $model::firstOrCreate(['name' => $name]);
    }

    /**
     * firstOrCreate a collection, keyed on (type, name, owner) — the same tuple
     * the DB dedup index enforces. `year` is written only on creation (legacy
     * behaviour); a later track never overwrites it.
     *
     * @param  array{album_artist_id?: ?string, author_id?: ?string}  $owner
     */
    private function collection(CollectionType $type, ?string $name, array $owner, ?int $year): ?MediaCollection
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }

        return MediaCollection::firstOrCreate(
            [
                'type' => $type,
                'name' => $name,
                'album_artist_id' => $owner['album_artist_id'] ?? null,
                'author_id' => $owner['author_id'] ?? null,
            ],
            ['year' => $year],
        );
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
     * `cascade` drops them when the row is deleted (data-model.md → (b) #4).
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

        $row->delete();
    }

    /**
     * Delete taxonomy/collections the diff left with no referrers. A diff (unlike
     * truncate) leaves these behind, and a browse list full of zero-track artists
     * is bad UX (data-model.md → (b) #5). Order matters: empty collections first,
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
                Narrator::query()->whereDoesntHave('tracks')->delete();
                Author::query()->whereDoesntHave('audiobooks')->delete();
                break;

            case TrackType::Podcast:
                // No contributor taxonomy for podcasts yet.
                break;
        }
    }

    private function announce(?Closure $progress, string $line): void
    {
        if ($progress !== null) {
            $progress($line);
        }
    }
}
