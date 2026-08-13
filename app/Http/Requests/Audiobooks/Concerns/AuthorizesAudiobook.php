<?php

namespace App\Http\Requests\Audiobooks\Concerns;

use App\Enums\CollectionType;
use App\Http\Requests\Music\Concerns\AuthorizesMusicAlbum;
use App\Models\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The routed collection is an AUDIOBOOK", for every route under /audiobooks that takes one.
 *
 * The mirror image of {@see AuthorizesMusicAlbum}, and it
 * exists for the same reason read the other way round: `collections` is a unified table, so a
 * bare UUID constraint would let an album's id resolve into a page built for books. The two
 * traits together are what keep `/music/albums/{id}` and `/audiobooks/{id}` from ever
 * rendering each other's rows.
 *
 * The model arrives already resolved — binding is substituted in middleware, before the
 * request is constructed — so `$this->route('audiobook')` is the Collection itself.
 */
trait AuthorizesAudiobook
{
    public function authorize(): bool
    {
        $audiobook = $this->route('audiobook');

        return $audiobook instanceof Collection && $audiobook->type === CollectionType::Audiobook;
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
