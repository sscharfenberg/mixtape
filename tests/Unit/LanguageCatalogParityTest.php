<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server-side translation files, held to the same shape in both languages.
 *
 * NOTHING ELSE CHECKS THIS. The client catalogs are at least type-derived from `de.json`, which
 * makes German keys visible to `vue-tsc`; these PHP arrays have no such backstop in either
 * direction. A key that goes missing from `lang/en/` reaches an English-reading user as the raw
 * dot-path it was asked for — `__('flash.playlist.created')` printed on the page — and every
 * other suite passes, because nothing asserts a translation was found.
 *
 * `validation.php` is the file this exists for: 164 keys, mostly a flat map of rule names, where
 * a group added on one side is invisible until the rule it belongs to actually fires.
 */
class LanguageCatalogParityTest extends TestCase
{
    /**
     * The repo root, derived from this file rather than from `base_path()`.
     *
     * A unit test here does not boot the framework — and the data provider below runs at
     * collection time, before any test does — so the helper is simply not available.
     */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * One case per file, so a failure names the file rather than the whole catalog.
     *
     * @return array<string, array<int, string>>
     */
    public static function catalogFiles(): array
    {
        $files = glob(self::root().'/lang/de/*.php') ?: [];

        return array_reduce($files, function (array $cases, string $path): array {
            $name = basename($path);

            return [...$cases, $name => [$name]];
        }, []);
    }

    #[DataProvider('catalogFiles')]
    public function test_both_languages_carry_the_same_keys(string $file): void
    {
        $german = require self::root()."/lang/de/{$file}";
        $english = require self::root()."/lang/en/{$file}";

        $this->assertSame($this->paths($german), $this->paths($english));
    }

    public function test_neither_language_has_a_file_the_other_lacks(): void
    {
        $names = static fn (string $locale): array => array_map(
            'basename',
            glob(self::root()."/lang/{$locale}/*.php") ?: []
        );

        $this->assertSame($names('de'), $names('en'));
    }

    #[DataProvider('catalogFiles')]
    public function test_both_languages_interpolate_the_same_placeholders(string $file): void
    {
        /*
         * A `:placeholder` present on one side and absent on the other renders literally — Laravel
         * substitutes what it finds and leaves the rest, so the reader sees ":attribute" in a
         * validation message rather than a field name, and nothing raises.
         */
        $german = $this->strings(require self::root()."/lang/de/{$file}");
        $english = $this->strings(require self::root()."/lang/en/{$file}");

        $mismatched = array_keys(array_filter(
            $german,
            fn (string $text, string $key): bool => $this->placeholders($text)
                !== $this->placeholders($english[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH
        ));

        $this->assertSame([], $mismatched);
    }

    /**
     * Every leaf key path in a nested translation array, dot-joined and sorted.
     *
     * Flattened rather than compared as nested arrays so a failure names the one key that
     * differs instead of printing both trees.
     *
     * @param  array<mixed>  $catalog
     * @return array<int, string>
     */
    private function paths(array $catalog, string $prefix = ''): array
    {
        $paths = [];

        foreach ($catalog as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $paths = [...$paths, ...(is_array($value) ? $this->paths($value, $path) : [$path])];
        }

        sort($paths);

        return $paths;
    }

    /**
     * Every leaf path mapped to its string, for the placeholder comparison.
     *
     * @param  array<mixed>  $catalog
     * @return array<string, string>
     */
    private function strings(array $catalog, string $prefix = ''): array
    {
        $strings = [];

        foreach ($catalog as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $strings = [...$strings, ...$this->strings($value, $path)];
            } elseif (is_string($value)) {
                $strings[$path] = $value;
            }
        }

        return $strings;
    }

    /**
     * The distinct `:placeholder` names a string interpolates, sorted.
     *
     * DE-DUPLICATED, because Laravel replaces every occurrence and a language may legitimately
     * mention one twice where the other says it once — German's `after_or_equal` reads "nach dem
     * :date oder gleich dem :date" against English's single ":date". What matters is that neither
     * side names a placeholder the other cannot fill; how often it says it is grammar.
     *
     * @return array<int, string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:(\w+)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
