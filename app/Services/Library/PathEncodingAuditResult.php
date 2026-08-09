<?php

declare(strict_types=1);

namespace App\Services\Library;

/**
 * What one run of {@see PathEncodingAudit} found, plus the two roll-ups the report is built
 * from.
 *
 * The aggregation lives here rather than in the renderer because it is the interesting half
 * and it is worth testing without going near Markdown: "which characters, how often" and
 * "what do I actually have to rename" are questions about the findings, not about layout.
 */
final class PathEncodingAuditResult
{
    /**
     * @param  int  $scanned  how many audio files were examined, so a report can say "89 of 12074"
     *                        rather than "89", which reads very differently
     * @param  PathEncodingFinding[]  $findings
     */
    public function __construct(
        public readonly int $scanned,
        public readonly array $findings,
    ) {}

    /** Whether the library is clean — the state this command exists to get you to. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * Every offending character, with how many paths it appears in, commonest first.
     *
     * Counted per PATH rather than per occurrence: the reader is deciding what to rename, and
     * a name containing three of the same character is still one rename.
     *
     * @return array<string, int> character => number of paths carrying it
     */
    public function offenderCounts(): array
    {
        $counts = [];

        foreach ($this->findings as $finding) {
            foreach ($finding->offenders as $character) {
                $counts[$character] = ($counts[$character] ?? 0) + 1;
            }
        }

        // Commonest first, ties broken by code point rather than by whatever order the walk
        // happened to meet them in — see renameTargets() for why the document must be stable.
        uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }

    /**
     * The distinct path SEGMENTS that need renaming, each with the number of files it affects.
     *
     * WHY THIS IS THE USEFUL VIEW, and not the flat list of paths. Offenders cluster in
     * directory names: one album folder called `F♯ A♯ ∞` makes every track under it
     * unencodable, so a flat list shows ten problems where there is one rename. Grouping by
     * segment turns the report into a work list — and it also catches the opposite mistake,
     * the one that actually happened here, where a directory was renamed but the six filenames
     * beneath it still carried the character.
     *
     * Keyed by area + full segment path so two identically-named folders in different places
     * stay separate. Ordered by how many files each fixes, because that is the order worth
     * working in.
     *
     * @return array<string, array{area: string, segment: string, parent: string, offenders: string[], files: int, isDirectory: bool}>
     */
    public function renameTargets(): array
    {
        $targets = [];

        foreach ($this->findings as $finding) {
            $segments = $finding->segments();
            $last = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                $offenders = PathEncodingAudit::offendersIn($segment);

                if ($offenders === []) {
                    continue;
                }

                $trail = implode('/', array_slice($segments, 0, $index + 1));
                $key = $finding->area->libraryPathKey().'/'.$trail;

                if (! isset($targets[$key])) {
                    $targets[$key] = [
                        'area' => $finding->area->libraryPathKey(),
                        'segment' => $segment,
                        'parent' => implode('/', array_slice($segments, 0, $index)),
                        'offenders' => $offenders,
                        'files' => 0,
                        'isDirectory' => $index < $last,
                    ];
                }

                $targets[$key]['files']++;
            }
        }

        // Biggest win first, then alphabetically — the trailing keys are not decoration: without
        // them equal-sized entries come out in whatever order the filesystem listed them, and
        // this report is meant to be re-run and compared against the last one.
        uasort($targets, fn (array $a, array $b) => [$b['files'], $a['parent'], $a['segment']]
            <=> [$a['files'], $b['parent'], $b['segment']]);

        return $targets;
    }
}
