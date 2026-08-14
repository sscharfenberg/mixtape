<?php

namespace App\Http\Requests\Playlists\Concerns;

use App\Models\Playlist;
use Illuminate\Validation\Rule;

/**
 * The metadata form's fields, shared by the create and the edit request.
 *
 * A trait rather than a common parent class, because the two requests share along TWO
 * axes that do not nest: the create shares these FIELDS with the edit, while the edit
 * shares its OWNERSHIP check with the edit-form request that has no fields at all
 * ({@see AuthorizesPlaylistOwnership}). One inheritance chain cannot express both, and
 * duplicating either is how the two halves of a form drift apart — the `max` only one
 * side enforces, the trim only one side does.
 */
trait ValidatesPlaylistMetadata
{
    /**
     * Clean the input BEFORE the rules see it.
     *
     * The ORDER is the point, not tidiness. Trim after validation — in the controller — and the
     * `unique` rule compares the raw value: "  Rock  " passes the check, is stored as "Rock",
     * and collides with the existing row at the database instead, surviving only because the
     * controller catches that violation and rethrows it as a field error. Cleaning first means
     * `max:255` and `unique` both measure the value that will actually be written.
     *
     * `description` is merged UNCONDITIONALLY so the key always exists: an edit that
     * cleared the field must store null, and a key absent from `validated()` would leave
     * the old blurb in place instead.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->string('name')->trim()->value(),
            // An empty textarea posts "", which is not the same as "no description":
            // stored as null so the page can ask one question ("is there a description?")
            // instead of two.
            'description' => $this->string('description')->trim()->value() ?: null,
        ]);
    }

    /**
     * `name` is unique PER OWNER — the same composite the migration enforces, so the rule
     * and the constraint say the same thing. On an EDIT the row being edited is ignored, or
     * a save that left the name alone would collide with itself and report the name as
     * taken by the very playlist wearing it.
     *
     * `description` is a `text` column with no length of its own; 1000 characters is a
     * blurb, and an unbounded textarea is a free megabyte in someone's database.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unique = Rule::unique('playlists', 'name')->where('user_id', $this->user()->id);
        $editing = $this->editedPlaylist();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                $editing === null ? $unique : $unique->ignore($editing->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Messages passed INLINE rather than added to validation.php's `custom` block, which is
     * keyed by attribute name alone and whose `name` entry belongs to the USERNAME — every
     * auth form in the app posts a `name` and means the login id. Left alone, a duplicate
     * playlist name would answer "this username is already taken", which renders, reads as
     * plausible, and is nonsense.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return __('playlist.validation');
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('playlist.attributes');
    }

    /**
     * The playlist being saved, or null when creating one.
     *
     * ABSTRACT rather than defaulted to null, because the update request also uses
     * {@see AuthorizesPlaylistOwnership}, which declares this method too — two traits
     * providing one name is a fatal collision PHP makes you resolve with `insteadof`.
     * Requiring it instead means the update satisfies this from the ownership trait, the
     * create answers null explicitly, and neither has to know about the other.
     */
    abstract protected function editedPlaylist(): ?Playlist;
}
