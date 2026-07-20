<?php

namespace App\Models;

use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An audiobook author. The owner of an audiobook collection
 * (`collections.author_id`), which is what fixes the legacy "no owner FK" dedup
 * bug (data-model.md → (b) #3). Scanner-managed; no timestamps; name unique +
 * case-insensitive.
 */
#[Fillable(['name'])]
class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /**
     * Audiobooks by this author. The collections CHECK pins `author_id` to
     * `type = 'audiobook'`, so these are always audiobooks.
     *
     * @return HasMany<Collection, $this>
     */
    public function audiobooks(): HasMany
    {
        return $this->hasMany(Collection::class, 'author_id');
    }
}
