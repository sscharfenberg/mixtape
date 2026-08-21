<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\AuditGroup;
use App\Services\Library\Audit\Contracts\RendersOwnSection;

/**
 * Renders an {@see AuditResult} as the Markdown document a person works through.
 *
 * WHY A FILE AND NOT CONSOLE OUTPUT. The findings are a work list — fix something, come back,
 * cross it off — and terminal scrollback is a poor place to keep one. Markdown because the reader
 * opens it in the editor they already have the collection open in, and because tables make a
 * character inventory legible where a flat list does not.
 *
 * IT EXPLAINS ITSELF ON PURPOSE. The document outlives the run that wrote it and will be read
 * weeks later, possibly by somebody who did not run it, so every section carries its own "why
 * this matters and what to do" rather than assuming the docs are open beside it.
 *
 * EVERY CHECK APPEARS, but only the ones with findings get a section. That split is the whole
 * layout: a summary table where a clean check costs one row is what makes twenty-five checks
 * readable, and it is also what tells a reader with a badly tagged library that the checks
 * reporting nothing actually ran. The browse-stats rule — a tile that can only read 0 is worse
 * than no tile — does not transfer here, because a row is not a tile competing for space.
 */
final class AuditReport
{
    /**
     * The whole document, ready to write.
     *
     * `$generatedAt` is passed in rather than read from the clock so a test can assert on the
     * output without freezing time, and so a run over two areas carries one stamp.
     */
    public static function render(AuditResult $result, string $generatedAt): string
    {
        return self::preamble($result, $generatedAt)
            .self::summary($result)
            .self::sections($result);
    }

    /** The title, the stamp, and what a reader is looking at — including what it will not do. */
    private static function preamble(AuditResult $result, string $generatedAt): string
    {
        $areas = implode(', ', array_map(fn ($area) => '`'.$area->libraryPathKey().'`', $result->areas));
        $scanned = $result->scanned > 0
            ? ' '.number_format($result->scanned).' audio file(s) walked on disk.'
            : '';
        // Interpolated rather than concatenated into the heredoc below: a `.CONST.` inside a
        // heredoc is literal text, which is the kind of thing that ships looking fine.
        $limit = number_format(CheckFindings::LIST_LIMIT);

        return <<<MD
        # Library audit

        Generated {$generatedAt} by `php artisan app:audit`. Areas: {$areas}.{$scanned}

        ## What this is

        Everything MixTape can tell is wrong with the library, in one list. **It reports and never
        repairs** — every finding is a decision only you can make, in a tagger or a file manager,
        and a scanner that guessed would quietly invent facts about your collection.

        Two things to read before acting on any of it. **Where a check got its facts** matters: a
        `database` check is only as fresh as the last `php artisan app:update`, while a `disk` check
        is true right now — which is why *Scan drift* comes first, because it measures the gap
        between them. And **a section can be truncated**: past the first {$limit} findings a section
        says how many it left out, so a count in the summary is always the real total even when the
        list under it is not.

        A check with nothing to report has a row in the summary and no section of its own.


        MD;
    }

    /**
     * The summary: one table per group, each under the promise its group makes.
     *
     * GROUPED RATHER THAN ONE LONG TABLE, because the queue's promise is the load-bearing one —
     * "most of these are legitimate" has to sit next to its numbers or 113 candidates read as 113
     * mistakes, and a reader who finds three false alarms in a row stops trusting the sections
     * above them.
     */
    private static function summary(AuditResult $result): string
    {
        $out = "## Summary\n\n**".number_format($result->total()).' finding(s)** across '
            .count($result->results)." check(s).\n\n";

        foreach ($result->groups() as $group) {
            $out .= '### '.$group->title()."\n\n".$group->blurb()."\n\n";
            $out .= "| Check | Source | Findings |\n| --- | --- | ---: |\n";

            foreach ($result->inGroup($group) as $check) {
                $out .= sprintf(
                    "| %s | %s | %s |\n",
                    self::checkLabel($check),
                    $check->source()->label(),
                    self::countLabel($check),
                );
            }

            $out .= "\n";
        }

        return $out;
    }

    /**
     * The check's name in the summary, linked to its section when it has one.
     *
     * The anchor is derived from the title the same way a Markdown renderer derives it, so the
     * two cannot disagree — and a check with no section is deliberately NOT a link, because a
     * link to nothing is worse than plain text.
     */
    private static function checkLabel(CheckResult $check): string
    {
        $title = $check->check->title();

        return $check->hasFindings() ? '['.$title.'](#'.self::anchor($title).')' : $title;
    }

    /** What the findings column says: a number, "clean", or why the check never ran. */
    private static function countLabel(CheckResult $check): string
    {
        if ($check->skipped !== null) {
            return '*skipped — '.$check->skipped.'*';
        }

        return $check->findings->isClean() ? 'clean' : '**'.number_format($check->findings->total).'**';
    }

    /** A section per check that found something, in registry order. */
    private static function sections(AuditResult $result): string
    {
        $out = '';

        foreach ($result->withFindings() as $check) {
            $out .= '## '.$check->check->title()."\n\n";

            // The group's promise, repeated per section rather than only in the summary: a reader
            // scrolling straight to a queue must not have to scroll back up to learn it is one.
            if ($check->group() === AuditGroup::Queue) {
                $out .= "*A review queue — most entries here are legitimate.*\n\n";
            }

            $out .= $check->check->blurb()."\n\n";
            $out .= $check->check instanceof RendersOwnSection
                ? $check->check->sectionBody()
                : self::table($check);
            $out .= "\n";
        }

        return $out;
    }

    /**
     * The default rendering: the findings as a table, and an honest note when it is cut short.
     *
     * NO SILENT CAPS. A truncated list that does not say so reads as "that is all of them", which
     * is the one wrong impression an audit must never leave — so the omitted count is printed
     * even though the summary already carries the total.
     */
    private static function table(CheckResult $check): string
    {
        $headers = array_merge(['Subject'], $check->check->columns());
        $out = '| '.implode(' | ', $headers)." |\n| ".implode(' | ', array_fill(0, count($headers), '---'))." |\n";

        foreach ($check->findings->listed as $finding) {
            $cells = array_merge([$finding->subject], $finding->cells);
            $out .= '| '.implode(' | ', array_map(self::cell(...), $cells))." |\n";
        }

        $omitted = $check->findings->omitted();

        return $out.($omitted > 0
            ? "\n…and ".number_format($omitted).' more, not listed ('.number_format($check->findings->total)." in total).\n"
            : '');
    }

    /**
     * One table cell, escaped so a path cannot break the table — and never altered.
     *
     * Two characters in a filename are hostile here, and both are legal on every filesystem this
     * app runs on. A PIPE ends the cell early and silently shifts every column after it, so it is
     * backslash-escaped. A BACKTICK would close the code span, and the obvious fix — swapping it
     * for an apostrophe — is the one thing this must not do: the reader has to find this file on
     * disk, so a cell that prints a name they cannot search for is worse than an ugly one. GFM lets
     * a code span be delimited by a longer run of backticks than it contains, which is what the
     * doubled fence below is.
     */
    private static function cell(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        $escaped = str_replace('|', '\\|', $value);

        // A space inside the fence keeps a leading or trailing backtick from merging with it.
        return str_contains($escaped, '`') ? '`` '.$escaped.' ``' : '`'.$escaped.'`';
    }

    /**
     * A heading's Markdown anchor: lower-cased, spaces to hyphens, punctuation dropped.
     *
     * The same rule GitHub and most renderers apply, so the summary's links resolve in whatever
     * the reader opens the file in.
     */
    private static function anchor(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9 \-]/', '', $slug) ?? $slug;

        return str_replace(' ', '-', trim($slug));
    }
}
