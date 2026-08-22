<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every relative link in the documentation resolves — the file, and the heading it points at.
 *
 * NOTHING ELSE CHECKS THIS, and the failure is silent by construction: a dead `#anchor` renders as
 * a perfectly ordinary link and simply scrolls nowhere, so it is only ever found by the one reader
 * who follows it, who is by definition looking for something they now cannot find. The docs here
 * lean on anchors heavily — the gotchas index in `docs/self-hosting/README.md` is a jump table of
 * nothing but deep links — so a section renamed or moved to another file breaks a dozen of them at
 * once, and none of them complain.
 *
 * TWO ANCHORS ARE AMBIGUOUS BY CONSTRUCTION and are what this exists for. GitHub disambiguates
 * repeated headings by appending `-1`, `-2`, so two sections both called "The decisions worth
 * knowing" are reachable only as `#the-decisions-worth-knowing` and `#the-decisions-worth-knowing-1`
 * — where the suffix depends on the ORDER the two appear in the file. Insert a third, or move one
 * to a different document, and every existing link still resolves; it just lands on the wrong
 * section. That is worse than a broken link, and a test comparing slugs is the only thing that sees
 * it.
 *
 * The slug rule mirrors GitHub's: strip inline code markers, lowercase, drop anything that is not a
 * letter, number, space, hyphen or underscore — which is what removes the em-dashes this project's
 * headings are full of — then spaces to hyphens.
 *
 * `docs/host.local/` is deliberately skipped. It is gitignored, so its contents differ per machine
 * and a failure there could be neither reproduced nor fixed by anyone else.
 */
class DocumentationLinksTest extends TestCase
{
    /** Directories with no documentation in them, or none of ours. */
    private const SKIP = [
        '.git', 'node_modules', 'vendor', 'storage', 'public', 'test-results',
        'playwright-report', 'docs/host.local',
    ];

    /**
     * Walk every markdown file and follow each relative link.
     *
     * Failures are collected rather than asserted one at a time: a renamed heading breaks many
     * links at once, and seeing all of them in one message is the difference between one fix and
     * a dozen runs.
     */
    public function test_every_documentation_link_resolves(): void
    {
        $root = dirname(__DIR__, 2);
        $files = self::markdownFiles($root);
        $anchors = [];
        $broken = [];

        foreach ($files as $file) {
            $anchors[$file] = self::anchorsIn($file);
        }

        foreach ($files as $file) {
            $from = str_replace($root.'/', '', $file);

            foreach (self::linksIn($file) as [$target, $anchor]) {
                // A link with neither a path nor a fragment is not a link to anything here.
                if ($target === '' && $anchor === null) {
                    continue;
                }

                $path = $target === ''
                    ? $file
                    : realpath(dirname($file).'/'.$target);

                if ($path === false || ! file_exists($path)) {
                    $broken[] = "{$from} → {$target} (no such file)";

                    continue;
                }

                if ($anchor === null || ! str_ends_with($path, '.md')) {
                    continue;
                }

                // A link may legitimately point into a file this walk skips.
                if (! array_key_exists($path, $anchors)) {
                    continue;
                }

                if (! in_array($anchor, $anchors[$path], true)) {
                    $broken[] = "{$from} → {$target}#{$anchor} (no such heading)";
                }
            }
        }

        $this->assertSame([], $broken, count($broken).' documentation link(s) do not resolve:'.PHP_EOL
            .implode(PHP_EOL, $broken));
    }

    /**
     * The walk itself, pinned.
     *
     * The way a test like this fails uselessly is by finding nothing — one wrong path constant and
     * it passes for ever while checking zero links. These numbers are floors, not counts, so they
     * do not need touching as the docs grow.
     */
    public function test_the_walk_actually_reaches_the_documentation(): void
    {
        $root = dirname(__DIR__, 2);
        $files = self::markdownFiles($root);

        $this->assertGreaterThan(10, count($files), 'the markdown walk found almost nothing');

        $links = 0;
        foreach ($files as $file) {
            $links += count(self::linksIn($file));
        }

        $this->assertGreaterThan(100, $links, 'the link extraction found almost nothing');
    }

    /**
     * Every markdown file in the project except the ones listed in {@see SKIP}.
     *
     * @return string[] absolute paths
     */
    private static function markdownFiles(string $root): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'md') {
                continue;
            }

            $relative = str_replace($root.'/', '', $entry->getPathname());

            foreach (self::SKIP as $skip) {
                if (str_starts_with($relative, $skip.'/') || str_contains($relative, '/'.$skip.'/')) {
                    continue 2;
                }
            }

            $found[] = $entry->getPathname();
        }

        sort($found);

        return $found;
    }

    /**
     * The anchors a file offers, in GitHub's spelling.
     *
     * Fenced code is skipped: a shell comment (`# Install the Node version …`) is not a heading,
     * and counting it as one would let a dead anchor resolve against it.
     *
     * @return string[]
     */
    private static function anchorsIn(string $file): array
    {
        $anchors = [];
        $seen = [];
        $fenced = false;

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fenced = ! $fenced;

                continue;
            }

            if ($fenced || preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }

            $slug = self::slug($m[2]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : $slug.'-'.$n;
        }

        return $anchors;
    }

    /**
     * The relative links in a file, as [target, anchor] pairs.
     *
     * External schemes are dropped here rather than in the caller: whether a URL is reachable is
     * the internet's business and not something a unit test should assert.
     *
     * @return array<int, array{0: string, 1: string|null}>
     */
    private static function linksIn(string $file): array
    {
        $text = file_get_contents($file) ?: '';
        $links = [];

        preg_match_all('/\]\(([^)\s#]*)(?:#([^)\s]+))?\)/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $target = $m[1];

            if (preg_match('#^(https?:|mailto:)#', $target) === 1) {
                continue;
            }

            $links[] = [$target, ($m[2] ?? '') === '' ? null : $m[2]];
        }

        return $links;
    }

    /** A heading rendered as GitHub renders it into a fragment. */
    private static function slug(string $heading): string
    {
        $text = preg_replace('/`([^`]*)`/', '$1', $heading) ?? $heading;
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N} \-_]+/u', '', $text) ?? $text;

        return str_replace(' ', '-', $text);
    }
}
