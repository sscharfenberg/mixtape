<?php

namespace App\Http\Requests\Shares\Concerns;

use App\Models\Share;
use App\Models\Track;
use App\Services\Shares\ShareGrant;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This link is still live, and that track is one it may play" — the whole permission
 * model of the `/s/` space, for the two routes that answer with bytes.
 *
 * BOTH HALVES ARE NEEDED AND NEITHER IS THE ROUTE'S SHAPE. Containment in the `/s/` space
 * is structural — a share cannot NAME a track outside its grant, because every media URL
 * is built from the grant — but a URL can be typed as easily as it can be followed, and a
 * guest holding a link to one song must not be able to swap the second UUID for another
 * and get the rest of the library. That is what {@see ShareGrant::contains()} refuses, over
 * the same query the guest page was drawn from.
 *
 * EXPIRY IS CHECKED HERE, not on the page. The page renders an expired share kindly (it
 * says so, and says to ask for a new link — docs/sharing.md), because the only person who
 * can reach that URL is someone who was handed it. Bytes are a different question: once the
 * week is up the link plays nothing, so the guard is on the routes that serve audio and art
 * rather than on the one that serves an explanation.
 *
 * The models arrive already resolved — route-model binding is substituted in middleware,
 * before the request is constructed — so this reads them off the route rather than the
 * database.
 */
trait AuthorizesShareTrack
{
    public function authorize(): bool
    {
        $share = $this->route('share');
        $track = $this->route('track');

        return $share instanceof Share
            && $track instanceof Track
            && $share->isLive()
            && ShareGrant::for($share)->contains($track->id);
    }

    /**
     * 404 for every refusal, which is the repo's rule and here also the only defensible
     * answer: a 403 on "that track is not in this grant" tells whoever typed the UUID that
     * the row exists, and this endpoint is reachable by anyone on the internet holding one
     * link. An expired share and a track from someone else's album are indistinguishable
     * from a typo, and should be.
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
