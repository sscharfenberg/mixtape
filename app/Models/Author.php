<?php

namespace App\Models;

use App\Models\Concerns\HasFoldedName;
use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An audiobook author, reached through the CHAPTERS they wrote (`tracks.author_id`).
 * Scanner-managed; no timestamps; name unique + case-insensitive.
 *
 * IT HANGS OFF THE TRACK, NOT THE BOOK — the same grain as `Narrator`. TCOM is a per-file
 * tag and an anthology uses it per story, so a book-level column both splits those books in
 * two and cannot say who wrote one chapter. The
 * tracks CHECK pins `author_id` to `type = 'audiobook'`, so everything here is audiobooks.
 */
#[Fillable(['name'])]
class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory, HasFoldedName, HasUuids;

    public $timestamps = false;

    /**
     * The chapters this author wrote. The grain the column actually has, and what the
     * orphan prune and the author's total playing time both read.
     *
     * @return HasMany<Track, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * The books this author contributed to, DISTINCT — an author with nine chapters in one
     * anthology is one book, and an anthology is a book of several authors, so this is a
     * many-to-many in everything but its storage.
     *
     * `belongsToMany` over `tracks`: the chapter carries both FKs, which makes it the pivot.
     *
     * QUALIFY EVERY COLUMN YOU NAME — `->count('collections.id')`, `->pluck('collections.name')`
     * — since `tracks` is a table in the join with columns of its own. A bare `->count()`
     * rewrites the select to `count(*)`, leaving the `distinct()` nothing to apply to, so an
     * author's six books come back as their sixty chapters, and a bare `->pluck('name')` is an
     * "ambiguous column name" error. Both measured, both silent.
     *
     * @return BelongsToMany<Collection, $this>
     */
    public function audiobooks(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'tracks', 'author_id', 'collection_id')->distinct();
    }
}
