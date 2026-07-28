<?php

namespace Tests\Unit\Search;

use App\Services\Search\FoldedSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The folding rule behind accent-insensitive search (FoldedSearch::fold).
 *
 * A plain unit test — no database — because the rule is a pure string function,
 * and pinning it here is what lets the DB side stay a single `LIKE`. It is also
 * the guard on a STORED value: change these expectations and every `name_fold`
 * column in the database is silently stale until re-scanned, so a failure here
 * means "you must backfill", not merely "you broke a helper".
 */
class FoldedSearchTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            // The everyday case: lowercase, and that is all.
            'ascii is only lowercased' => ['Groundhog Day', 'groundhog day'],
            // Latin diacritics, the whole point of the exercise.
            'polish ł' => ['Mgła', 'mgla'],
            'circumflex' => ['Lantlôs', 'lantlos'],
            'umlaut' => ['Fräulein Wunder', 'fraulein wunder'],
            'nordic' => ['Sigur Rós', 'sigur ros'],
            // Multi-character expansions — one character in, two out.
            'eszett expands' => ['Straße', 'strasse'],
            'ligature expands' => ['Æther', 'aether'],
            // Cyrillic transliterates, which Postgres' own unaccent() does NOT do.
            'cyrillic' => ['Кино', 'kino'],
            // CJK has no ASCII form and is KEPT rather than dropped: this is what
            // makes the fold column a superset of the raw one, so "暴君" stays
            // findable and the search needs no second pass over `name`.
            'cjk survives folding' => ['Bloody Tyrant 暴君', 'bloody tyrant 暴君'],
            'cjk only' => ['暴君', '暴君'],
        ];
    }

    #[DataProvider('names')]
    public function test_it_folds_a_name_for_searching(string $input, string $expected): void
    {
        $this->assertSame($expected, FoldedSearch::fold($input));
    }

    public function test_a_null_name_folds_to_null(): void
    {
        // Nullable on `collections`/taxonomy joins and on the search pattern path.
        $this->assertNull(FoldedSearch::fold(null));
    }

    public function test_folding_is_idempotent(): void
    {
        // Re-folding an already folded value must not change it — the backfill and
        // the mutator both fold from the raw name, but a re-scan that folds twice
        // (or a future caller that folds a stored value) must be harmless.
        foreach (self::names() as [$input, $expected]) {
            $this->assertSame($expected, FoldedSearch::fold($expected), "re-folding '{$input}' changed it");
        }
    }

    public function test_a_decomposed_diacritic_folds_like_a_composed_one(): void
    {
        // "é" typed as e + U+0301 (macOS filenames do this) must fold to the same
        // "e" as the single-codepoint form, or the same album would land under two
        // different folds depending on how the tagger wrote it.
        $this->assertSame(
            FoldedSearch::fold("\u{00E9}cole"),      // é
            FoldedSearch::fold("e\u{0301}cole")     // e + combining acute
        );
    }
}
