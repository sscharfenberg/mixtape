<?php

namespace App\Models;

use Database\Factories\NarratorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An audiobook narrator, referenced per-track (`tracks.narrator_id`). Read from
 * the `artist` ID3 frame by the audiobook scanner. Scanner-managed; no
 * timestamps; name unique + case-insensitive.
 */
#[Fillable(['name'])]
class Narrator extends Model
{
    /** @use HasFactory<NarratorFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }
}
