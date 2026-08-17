<?php

declare(strict_types=1);

namespace App\Http\Requests\History;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which page of the listening history to draw (`GET /history`).
 *
 * ONE PARAMETER, because the page has one control: the reader pages through days and chooses
 * nothing else. There is deliberately no page SIZE here, unlike every DataTable in the app —
 * a history is read in days rather than rows, twenty-five of them is a month at a glance, and
 * a size control on a list with one column is a setting nobody came here to make.
 *
 * NO `authorize()`, and that is the honest shape rather than an omission: there is no subject
 * to guard. The route group supplies `auth`, and the controller scopes every query to
 * `$request->user()`, so a reader can only ever ask for a page of their OWN listening — there
 * is no id in the URL that could name somebody else's.
 */
class ShowHistoryRequest extends FormRequest
{
    /**
     * The page number, when one is asked for.
     *
     * `sometimes`, because no parameter means the first page — the ordinary way to arrive
     * here. What the rules are actually defending against is a hand-typed `?page=-3` or
     * `?page=abc` reaching the paginator, which answers an empty list for the first and
     * treats the second as page 1: a 422 says which of the two happened.
     *
     * `nullable` IS NOT SPARE. `ConvertEmptyStringsToNull` is a global middleware, so `?page=`
     * arrives with the key present and the value **null** — `sometimes` therefore lets it
     * through and `integer` refuses it, answering 422 for a URL that was plainly asking for the
     * first page. The same trap CLAUDE.md records for `GET /search?kinds=`. Null then reaches
     * the paginator, which resolves it to page 1.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
