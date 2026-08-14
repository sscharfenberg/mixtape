<?php

namespace App\Http\Requests\Music\Concerns;

use App\Enums\TrackType;
use App\Models\Track;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The routed track is MUSIC", for every route under /music that takes one.
 *
 * `tracks` is a unified table — audiobook chapters live in it too — so a UUID-constrained
 * route is not enough on its own: a chapter's id would resolve and then be served by a
 * song page, a cover route or the music stream. Stated once here rather than repeated at
 * each of the three call sites.
 *
 * The model arrives already resolved, binding having been substituted in middleware.
 */
trait AuthorizesMusicTrack
{
    public function authorize(): bool
    {
        $song = $this->route('song');

        return $song instanceof Track && $song->type === TrackType::Music;
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
