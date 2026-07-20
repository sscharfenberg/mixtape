<?php

namespace App\Models;

use Database\Factories\GenreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A music genre, referenced per-track (`tracks.genre_id`). Audiobooks drop the
 * genre frame, so only music tracks carry one (enforced by the tracks CHECK).
 * Scanner-managed; no timestamps; name unique + case-insensitive.
 */
#[Fillable(['name'])]
class Genre extends Model
{
    /** @use HasFactory<GenreFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }
}
