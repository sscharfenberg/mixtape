<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Enums\SearchKind;
use App\Models\User;
use App\Services\Search\SearchGroup;

/**
 * One entry in the search registry — everything `LibrarySearch` needs to ask a kind for its
 * answer, and nothing else.
 *
 * AN INTERFACE RATHER THAN A UNION IN THE ORCHESTRATOR, because the list of kinds grows:
 * audiobooks joined it as one class plus one registry line, which is the whole of what a new
 * kind should cost (docs/search.md → "The kinds are a registry, not a union").
 * The alternative — a `match` over the enum inside one big service — makes every new kind an
 * edit to the ranking, the counting and the shaping at once.
 *
 * The reader is passed in rather than reached for, because ONE kind needs it: a playlist
 * belongs to somebody, so the same query for two accounts must answer differently. Every other
 * kind ignores it, which is exactly what makes it worth stating in the signature.
 */
interface SearchableKind
{
    /** Which kind this is — the group's label on the client, and its place in the fixed order. */
    public function kind(): SearchKind;

    /**
     * This kind's answer for `$query`: the real total, the top `$limit` rows, and a hand-off
     * where one exists.
     *
     * @param  string  $query  the reader's raw query; folding is the implementation's business
     * @param  User  $reader  who is asking — load-bearing only for the kinds they own
     * @param  int  $limit  how many rows to carry; the total is never limited by it
     */
    public function group(string $query, User $reader, int $limit): SearchGroup;
}
