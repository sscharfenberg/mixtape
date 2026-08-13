<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Search\SearchRequest;
use App\Services\Search\LibrarySearch;
use Illuminate\Http\JsonResponse;

/**
 * The cross-kind search endpoint (`GET /search`, route `search`, behind auth) — what the
 * header overlay and the Music page's field both call as a reader types (docs/search.md → "The
 * endpoint").
 *
 * JSON RATHER THAN AN INERTIA PARTIAL RELOAD, which is the one architectural decision here
 * worth defending in an app that has no REST API by design. A typeahead firing `router.reload`
 * would re-render the page component on every debounce and push history entries — and
 * re-creating a page under a reader who is typing is precisely the documented data-loss trap
 * (CLAUDE.md → the prefetch rule): `setup()` runs again and every `ref` on the page goes back
 * to its prop. Two endpoints already exist for the same reason, minting a share and syncing the
 * player state, and this is the third of the same kind: the caller is not navigating, it wants
 * rows to draw.
 *
 * `private, no-store` IS NOT BOILERPLATE. One of the five kinds is the reader's own playlists,
 * so two accounts in one household typing the same three letters get different totals — a
 * response cached anywhere, by a proxy or by the browser's own history, would show one of them
 * the other's list. Nothing about the URL says the answer is per-person, which is exactly why
 * the header has to.
 *
 * THE THROTTLE BUCKET IS NAMED (`throttle:60,1,search`) per the repo rule: an unprefixed
 * numeric throttle keys on the caller alone and would share one counter with every other
 * unprefixed route. Sixty a minute is generous against a 200 ms debounce and still bounds a
 * client stuck in a loop.
 */
class SearchController extends Controller
{
    /**
     * Answer one query with a group per kind that has something to say.
     *
     * A thin action on purpose: the request owns what may be asked, the service owns the
     * registry and the order, and the kinds own their own queries. What is left here is the two
     * facts that belong to the HTTP layer — the shape of the envelope, and that the answer must
     * never be stored.
     */
    public function __invoke(SearchRequest $request, LibrarySearch $search): JsonResponse
    {
        $groups = $search->search(
            query: $request->validated()['q'],
            kinds: $request->kinds(),
            reader: $request->user(),
        );

        return response()
            ->json(['groups' => $groups])
            ->header('Cache-Control', 'private, no-store');
    }
}
