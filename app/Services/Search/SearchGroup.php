<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchKind;

/**
 * One kind's answer: how many there really are, the first few of them, and where to see the
 * rest.
 *
 * `total` IS THE REAL COUNT, not the length of `rows`, and that is the whole reason this is a
 * group rather than a flat list: a header saying "77" while showing five is honest, and a
 * header showing five while there are seventy-seven tells a reader they have seen everything.
 * It is also what decides whether a hand-off is offered at all — see `seeAll`.
 */
final readonly class SearchGroup
{
    /**
     * @param  SearchKind  $kind  which kind these rows are; the client reads it for the group heading and the meta wording
     * @param  int  $total  every match for this kind, not just the ones carried here
     * @param  list<SearchHit>  $rows  the top few, already ranked (SearchRanking)
     * @param  string|null  $seeAll  the listing at `?search=…`, or null when there is nothing more to see — or no listing to see it in (playlists)
     */
    public function __construct(
        public SearchKind $kind,
        public int $total,
        public array $rows,
        public ?string $seeAll = null,
    ) {}

    /** @return array{kind: string, total: int, rows: list<array<string, mixed>>, seeAll: string|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'total' => $this->total,
            'rows' => array_map(fn (SearchHit $hit): array => $hit->toArray(), $this->rows),
            'seeAll' => $this->seeAll,
        ];
    }
}
