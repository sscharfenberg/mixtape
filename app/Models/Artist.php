<?php

namespace App\Models;

use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A music performer. Referenced both as a track's performer (`tracks.artist_id`)
 * and as an album's owner (`collections.album_artist_id`). Minted and pruned by
 * the library scanner, so it carries no timestamps of its own; its `name` is
 * unique and case-insensitive (data-model.md → (b) #2).
 */
#[Fillable(['name'])]
class Artist extends Model
{
    /** @use HasFactory<ArtistFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * Albums credited to this artist (album_artist). The collections CHECK pins
     * `album_artist_id` to `type = 'album'`, so these are always albums.
     *
     * @return HasMany<Collection, $this>
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Collection::class, 'album_artist_id');
    }
}
