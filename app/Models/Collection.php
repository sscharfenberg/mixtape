<?php

namespace App\Models;

use App\Enums\CollectionType;
use App\Models\Concerns\HasFoldedName;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A container of tracks — the merged albums + audiobooks table (data-model.md →
 * "One tracks table, one collections table"). `type` says which container kind it is and
 * a DB CHECK ties the owner FK to it: `album_artist` only on albums.
 *
 * AN AUDIOBOOK HAS NO OWNER COLUMN. Its author is a property of each
 * chapter (`tracks.author_id`), because an anthology names a different one per story —
 * so a book's authors are read through its tracks, and it dedupes on its title alone.
 */
#[Fillable(['type', 'name', 'year', 'cover_path', 'album_artist_id'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory, HasFoldedName, HasUuids;

    /**
     * `cover_path` needs no cast: it is a plain area-relative path (like
     * `tracks.path`), nullable when this container has no directory image — see the
     * migration for why it holds a path where `tracks.cover` holds a boolean.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CollectionType::class,
            'year' => 'integer',
        ];
    }

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /** The album-artist (music albums only). @return BelongsTo<Artist, $this> */
    public function albumArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'album_artist_id');
    }

    /**
     * The authors of this book, DISTINCT — an anthology has several and an ordinary book
     * has one, so the shape is a list either way rather than a nullable belongsTo.
     *
     * `belongsToMany` OVER `tracks`, not `hasManyThrough`: the FK sits on the chapter
     * (`tracks.author_id`), which makes the chapter a pivot rather than an intermediate
     * owner. hasManyThrough reads the far table for the key and finds nothing there.
     *
     * QUALIFY EVERY COLUMN YOU NAME, because the pivot is a table with its own `name`:
     * `->count('authors.id')` and `->pluck('authors.name')`. A bare `->count()` rewrites the
     * select to `count(*)`, leaving the `distinct()` nothing to apply to, so an author with
     * nine chapters in one book counts nine times; a bare `->pluck('name')` is an outright
     * "ambiguous column name" error. Both measured, both silent.
     *
     * @return BelongsToMany<Author, $this>
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'tracks', 'collection_id', 'author_id')->distinct();
    }

    /**
     * The narrators of this book, DISTINCT — same shape and same reason as {@see authors()}.
     *
     * @return BelongsToMany<Narrator, $this>
     */
    public function narrators(): BelongsToMany
    {
        return $this->belongsToMany(Narrator::class, 'tracks', 'collection_id', 'narrator_id')->distinct();
    }
}
