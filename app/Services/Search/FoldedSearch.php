<?php

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Accent- and case-insensitive substring search, via the `name_fold` companion
 * columns (data-model.md → (c), substring search).
 *
 * Every searchable `name` has a `name_fold` sibling holding its folded form, kept
 * in step by the models' HasFoldedName mutator. Searching that column rather than
 * folding inside SQL is what keeps ONE code path: `unaccent()` is Postgres-only,
 * and Postgres refuses LIKE / ILIKE / regex on the nondeterministic
 * `case_insensitive` ICU collation the raw name columns carry — so folding in SQL
 * would need per-driver SQL plus a `COLLATE "C"` pin, and the search would stay
 * unrunnable (a hard 500) on the sqlite test DB. The fold column keeps the default
 * deterministic collation, so a plain `like` works identically on both.
 */
class FoldedSearch
{
    /**
     * The one folding rule: lowercase, then strip diacritics — while KEEPING any
     * character that has no ASCII form (CJK, symbols) instead of dropping it.
     *
     * That last part is what makes the folded column a superset of the raw one:
     * "Mgla" finds "Mgła" *and* "暴君" still finds "Bloody Tyrant 暴君", so the
     * search never needs a second pass over the raw columns — which is exactly
     * what would drag the per-driver COLLATE pin back in.
     *
     * Built on Str::ascii's fixed charmap rather than ext-intl's Transliterator on
     * purpose: the result is STORED, so it must come out identical on the dev Mac
     * and on the server, and an ICU version bump must not silently invalidate a
     * whole column. (Str::ascii also folds Cyrillic, which Postgres' `unaccent`
     * does not.) NFC normalisation runs first where ext-intl is present, so a
     * decomposed "e"+U+0301 folds like a composed "é".
     */
    public static function fold(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        $lower = mb_strtolower($value);

        // Fast path — a pure-ASCII name (the vast majority) needs no
        // transliteration, only the lowercasing already done. Byte length ==
        // character length is the cheap "is this ASCII?" test.
        if (strlen($lower) === mb_strlen($lower)) {
            return $lower;
        }

        $folded = '';
        foreach (mb_str_split($lower) as $character) {
            $ascii = Str::ascii($character);
            $folded .= $ascii === '' ? $character : $ascii;
        }

        return $folded;
    }

    /**
     * OR a folded substring match across the given columns — the search half of a
     * DataTable response.
     *
     * Callers name the RAW columns they mean ("tracks.name"); each is matched
     * through its `_fold` sibling, so a listing's searchable set reads as the
     * columns it shows rather than as a set of derived column names. Wrapped in a
     * nested where() so the ORs cannot escape and swallow the caller's own
     * constraints (the `type = music` scope, say).
     *
     * @param  string[]  $columns  raw column names, e.g. ['tracks.name', 'artists.name']
     */
    public static function apply(Builder $query, string $search, array $columns): void
    {
        $pattern = '%'.self::fold($search).'%';

        $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $q->orWhere($column.'_fold', 'like', $pattern);
            }
        });
    }
}
