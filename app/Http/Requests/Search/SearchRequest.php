<?php

declare(strict_types=1);

namespace App\Http\Requests\Search;

use App\Enums\SearchKind;
use App\Services\Search\LibrarySearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query behind `GET /search`: what to look for, and which kinds may answer.
 *
 * NO `authorize()`, and that is the honest shape rather than an omission: there is no subject
 * to guard. The route group supplies `auth`, the library is every account's alike, and the one
 * user-scoped kind narrows itself by `user_id` in its own query (PlaylistKind) rather than by a
 * check here — so a stranger's playlist is not refused, it simply is not in the set. A search
 * that answered 403 for anything would be confirming that the thing exists.
 *
 * THE THREE-CHARACTER FLOOR IS ENFORCED HERE, not on the client alone. The client has the same
 * rule so it never sends a pointless request, but a floor that only lives in the browser is a
 * floor a URL can walk past — and `%b%` over 12k rows is a scan a trigram index cannot help.
 */
class SearchRequest extends FormRequest
{
    /**
     * What the reader typed, and which kinds may answer.
     *
     * `kinds` needs neither `sometimes` nor `nullable`: `prepareForValidation` below has
     * already turned every wire form of "no filter" — absent, empty, blank — into the empty
     * array, so by the time these rules run it is always a list. What is left to check is that
     * each entry names a real kind, which is where a hand-written `?kinds=podcast` is refused.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.LibrarySearch::MIN_LENGTH, 'max:255'],
            'kinds' => ['array'],
            'kinds.*' => [Rule::enum(SearchKind::class)],
        ];
    }

    /**
     * Clean the query and unpack the kind filter BEFORE the rules see either.
     *
     * TRIMMING FIRST IS WHAT MAKES THE FLOOR MEAN ANYTHING: `"  a  "` is five characters of
     * input and one character of question, and the rules must measure what will actually be
     * searched. An emptied query becomes null so it fails `required` as the missing thing it
     * is, rather than as a string that is too short.
     *
     * `kinds` arrives as ONE COMMA-SEPARATED PARAMETER (`?kinds=album,artist`), because this is
     * a query string a human may well type and `kinds[]=` repeated is not. An array form is
     * accepted all the same, so a caller that sends one is not punished for it.
     *
     * EVERY WAY OF SAYING "NO FILTER" COLLAPSES TO THE EMPTY ARRAY — absent, `?kinds=`, a
     * trailing comma, a blank entry. That last part is not tidiness: `ConvertEmptyStringsToNull`
     * is a global middleware, so `?kinds=` reaches this method as **null** rather than as `''`,
     * and a `sometimes|array` rule then reports "kinds must be an array" for a URL that was
     * plainly not filtering at all. Casting through `(string)` before splitting is what makes
     * null, `''` and a missing parameter one case instead of three.
     */
    protected function prepareForValidation(): void
    {
        $query = trim((string) $this->input('q'));

        $raw = $this->input('kinds');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        $this->merge([
            'q' => $query === '' ? null : $query,
            'kinds' => array_values(array_filter(
                array_map(fn (mixed $kind): mixed => is_string($kind) ? trim($kind) : $kind, $list),
                fn (mixed $kind): bool => $kind !== '' && $kind !== null,
            )),
        ]);
    }

    /**
     * The requested kinds as enum cases — an empty list meaning "every kind".
     *
     * Here rather than in the controller because it is the last step of reading this request:
     * the rules have already proved every entry names a case, so this cannot fail and the
     * service can take a typed list instead of strings off a URL.
     *
     * @return list<SearchKind>
     */
    public function kinds(): array
    {
        /** @var list<string> $kinds */
        $kinds = $this->validated()['kinds'] ?? [];

        return array_map(fn (string $kind): SearchKind => SearchKind::from($kind), $kinds);
    }
}
