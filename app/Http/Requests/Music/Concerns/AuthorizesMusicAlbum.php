<?php

namespace App\Http\Requests\Music\Concerns;

use App\Enums\CollectionType;
use App\Models\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The routed collection is a music ALBUM", for every route under /music that takes one.
 *
 * `collections` is a unified table — it holds audiobooks too — so a bare UUID constraint on
 * the route is not enough: an audiobook's id would resolve and then be rendered by a page
 * built for albums. The check was written out at each call site until this trait; stated
 * once, the five music routes cannot drift on what they accept.
 *
 * The model arrives already resolved: route-model binding is substituted in middleware,
 * before the request is constructed, so `$this->route('album')` is the Collection itself.
 */
trait AuthorizesMusicAlbum
{
    /**
     * The routed collection must be an ALBUM — `collections` holds audiobooks too, so a UUID-bound
     * route would otherwise serve a book through the album pages.
     */
    public function authorize(): bool
    {
        $album = $this->route('album');

        return $album instanceof Collection && $album->type === CollectionType::Album;
    }

    /**
     * 404, not the FormRequest default of 403.
     *
     * Nothing here is a permission — the row simply is not the kind of thing this route is
     * about, which to a caller is indistinguishable from it not existing, and should be.
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
