<?php

namespace App\Models;

use App\Enums\CollectionType;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A container of tracks — the merged albums + audiobooks table (data-model.md →
 * (a), "the collections half-step"). `type` says which container kind it is and
 * a DB CHECK ties the owner FK to it: `album_artist` only on albums, `author`
 * only on audiobooks (podcast shows have neither).
 */
#[Fillable(['type', 'name', 'year', 'cover', 'album_artist_id', 'author_id'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CollectionType::class,
            'year' => 'integer',
            'cover' => 'boolean',
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

    /** The author (audiobooks only). @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
