<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Album directories holding no image the cover service recognises. */
final class AlbumsWithoutFolderImageCheck extends CollectionCheck
{
    /** Structure: the fix is a file in a directory, not a tag in a file. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Music only — a book's cover follows the same rule but has never been short of one here. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** "Folder image" and not "cover", because an album with no folder image still shows one. */
    public function title(): string
    {
        return 'Albums with no folder image';
    }

    /** What the reader loses, and the two shapes the fix takes. */
    public function blurb(): string
    {
        return 'An album prefers the ONE image chosen for it as a whole and only falls back to a file\'s embedded '
            .'picture — so without a folder image the album\'s thumbnail is whichever track happens to sort first, '
            .'which on a compilation or a live set is arbitrary. Either the directory holds no image at all (add '
            .'`folder.jpg`), or it holds several unrecognised ones (`back.jpg`, `booklet.jpg`, `cd.jpg`), in which '
            .'case renaming the front cover to `folder.jpg` is the whole fix. A directory holding exactly one '
            .'image is already used whatever it is called, so it never appears here.';
    }

    /**
     * No recorded folder image.
     *
     * READ FROM THE COLUMN the scanner writes (`collections.cover_path`), not by walking the
     * directory: `app:update` already resolved every album's image against
     * `mixtape.covers.folder_images` and stored the answer, so asking again would be a directory
     * read per album to re-derive a decision that is already made — and it would be free to
     * disagree with what the app actually serves, which is the one thing a report must not do.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return $collections->whereNull('collections.cover_path');
    }
}
