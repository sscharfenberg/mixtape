<?php

namespace App\Http\Requests\Audiobooks\Concerns;

use App\Enums\TrackType;
use App\Http\Requests\Music\Concerns\AuthorizesMusicTrack;
use App\Models\Track;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The routed track is an audiobook CHAPTER", for every route under /audiobooks that takes
 * one. The mirror of {@see AuthorizesMusicTrack}: `tracks`
 * is a unified table, so without this a song's id would resolve and be served by the chapter
 * stream.
 *
 * THE CHAPTER ROUTES ARE FLAT — `/audiobooks/chapters/{chapter}/…`, not nested under the
 * book — which is why this asks one question rather than two. Nesting was the first shape and
 * was dropped: every one of these routes sits behind `auth` and any reader may play any
 * chapter, so containment would buy no authorization, only a tidier URL, and it would cost
 * the case of a chapter whose file carries no album tag — no `collection_id`, so no nested
 * URL can be built for it, and the player would be handed a null `streamUrl`. Music is flat
 * for the same reason and the two areas now read alike.
 *
 * The model arrives already resolved, binding having been substituted in middleware.
 */
trait AuthorizesAudiobookChapter
{
    public function authorize(): bool
    {
        $chapter = $this->route('chapter');

        return $chapter instanceof Track && $chapter->type === TrackType::Audiobook;
    }

    /**
     * 404, not the FormRequest default of 403.
     *
     * Nothing here is a permission — the row is not the kind of thing this route is about,
     * which to a caller is indistinguishable from it not existing, and should be.
     */
    protected function failedAuthorization(): never
    {
        abort(Response::HTTP_NOT_FOUND);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
