<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\TrackType;
use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A playable row — one unified table for music, audiobook chapters, and (future)
 * podcast episodes (option B). Identity is the audio-stream `content_hash`, not
 * the `path`, so a rename or re-tag keeps the id (data-model.md → "the one
 * fact"); two files with identical audio are two rows (clones) sharing a hash.
 *
 * `path` is stored RELATIVE to the area root (e.g. `Artist/Album/01.mp3`), never
 * the absolute server path, so relocating the collection doesn't touch the DB.
 * Use `absolutePath()` to get the real file location. Uniqueness is `(type,
 * path)` — one file per area.
 */
#[Fillable([
    'type', 'collection_id', 'artist_id', 'genre_id', 'narrator_id',
    'composer', 'publisher', 'name', 'path', 'content_hash', 'size',
    'modified_at', 'codec', 'channel', 'duration', 'sample_rate', 'bit_rate',
    'vbr', 'cover', 'track', 'disc',
])]
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory, HasUuids;

    /**
     * Only `created_at` is tracked (a stable insert-time "date added"); there is
     * no `updated_at` column — re-tags/renames are UPDATEs we don't want to bump
     * a modified timestamp on (data-model.md → (c)).
     */
    const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TrackType::class,
            'channel' => Channel::class,
            'duration' => 'float',
            'size' => 'integer',
            'sample_rate' => 'integer',
            'bit_rate' => 'integer',
            'track' => 'integer',
            'disc' => 'integer',
            'vbr' => 'boolean',
            'cover' => 'boolean',
            'modified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The absolute path to the file on disk, rebuilt from the configured area
     * root and the stored area-relative `path`. Everything that touches the real
     * file — the scanner, and (later) the player and cover-art extraction —
     * resolves it through here rather than assuming `path` is absolute.
     */
    public function absolutePath(): string
    {
        $root = rtrim((string) config('mixtape.library.paths.'.$this->type->libraryPathKey()), '/');

        return $root.'/'.$this->path;
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<Genre, $this> */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /** @return BelongsTo<Narrator, $this> */
    public function narrator(): BelongsTo
    {
        return $this->belongsTo(Narrator::class);
    }

    /** @return HasMany<PlaylistTrack, $this> */
    public function playlistTracks(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class);
    }

    /** @return HasMany<Play, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /**
     * Other rows with byte-identical audio — the "x clones" feature (same
     * `content_hash`, different id). Powers "also appears in N other places", the
     * scanner's relink-to-clone on delete, and most-played-by-recording.
     *
     * Not an Eloquent relationship (there is no FK between clones), so it returns
     * a query builder rather than a Relation.
     *
     * @return Builder<Track>
     */
    public function clones(): Builder
    {
        return static::query()
            ->where('content_hash', $this->content_hash)
            ->whereKeyNot($this->getKey());
    }
}
