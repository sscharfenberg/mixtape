<?php

namespace App\Http\Requests\Shares;

use App\Models\Share;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the SUBJECT's own artwork (`GET /s/{share}/cover`) — the picture in the guest
 * page's hero, as opposed to the per-track thumbnails beside its rows.
 *
 * ONLY THE CLOCK IS CHECKED, because there is no second id to contain: the route names the
 * share and nothing else, so what it may serve is settled by which row it resolved. That is
 * the same reason this cannot share AuthorizesShareTrack — half of that trait's job does
 * not exist here.
 */
class ShareCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $share = $this->route('share');

        return $share instanceof Share && $share->isLive();
    }

    /**
     * 404 rather than the FormRequest default of 403 — the rule the whole `/s/` space
     * follows: this endpoint is reachable by anyone holding a link, and a 403 would confirm
     * that the row behind a guessed URL exists.
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
