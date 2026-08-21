<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Services\Library\Audit\Contracts\RendersOwnSection;
use IntlChar;
use Normalizer;

/**
 * Renders a {@see PathEncodingAuditResult} as the Markdown SECTION `app:audit` prints for it.
 *
 * WHY A FILE AND NOT CONSOLE OUTPUT. The findings are a work list — you rename something, come
 * back, cross it off — and 89 lines of terminal scrollback is a bad place to keep one. Markdown
 * because the reader opens it in the editor they already have the collection open in, and
 * because tables make the character inventory legible where a flat list does not.
 *
 * THE DOCUMENT EXPLAINS ITSELF ON PURPOSE. It outlives the command run and will be read weeks
 * later, possibly by someone who did not run it, so it carries its own "why this matters"
 * rather than assuming the reader has the docs open. That is also why it names every character:
 * half of these are invisible on screen, and "rename this file" is useless advice when the file
 * looks perfectly ordinary.
 */
final class PathEncodingReport
{
    /** Where the interesting half of an unnamed character lives, when IntlChar has no name for it. */
    private const UNNAMED = 'no Unicode name (private-use area)';

    /**
     * This check's section of the audit document — everything below its heading.
     *
     * A SECTION AND NOT A DOCUMENT, which is what changed when the encoding audit stopped being a
     * command of its own: the shell (title, generation stamp, summary table, what to do
     * afterwards) belongs to the report that now carries twenty-five checks, and duplicating a
     * preamble per check would bury the findings. What stays here is the part no table can hold —
     * see {@see RendersOwnSection}.
     *
     * Headings start at level 3 because the audit's own check heading is level 2.
     */
    public static function section(PathEncodingAuditResult $result): string
    {
        if ($result->isClean()) {
            return "Every path in the library can be written to a Windows-1252 playlist as it stands.\n";
        }

        return self::characters($result).self::work($result).self::appendix($result).self::afterwards();
    }

    /**
     * The character inventory: what each offender is, and what to do about it.
     *
     * This table is the part that turns an impossible bug report into a chore. Roughly half of
     * what turns up in a real collection is invisible on screen — private-use characters a
     * tagger swapped in for `?` or `"`, exotic spaces, a combining accent that renders exactly
     * like a normal one — and no amount of staring at the filename reveals them.
     */
    private static function characters(PathEncodingAuditResult $result): string
    {
        $out = "### The characters\n\n"
            ."Counted by how many paths carry them. A glyph column of `—` means the character has no\n"
            ."visible form — that is the point of listing it.\n\n"
            ."| Char | Code point | Name | Paths | What to do |\n| :---: | --- | --- | ---: | --- |\n";

        foreach ($result->offenderCounts() as $character => $count) {
            $glyph = self::isVisible($character) ? '`'.self::tableCode($character).'`' : '—';
            $out .= sprintf(
                "| %s | `%s` | %s | %d | %s |\n",
                $glyph,
                self::codePoint($character),
                self::tableText(self::nameOf($character)),
                $count,
                self::tableText(self::advice($character)),
            );
        }

        return $out."\n";
    }

    /**
     * The work list: the distinct names to change, biggest win first.
     *
     * Grouped by segment rather than listed per file because offenders cluster in folder names —
     * one album directory can account for a dozen findings and one rename. It also exposes the
     * opposite mistake, which is the easy one to make: renaming the folder and leaving the
     * filenames beneath it untouched.
     */
    private static function work(PathEncodingAuditResult $result): string
    {
        $out = "### What to rename\n\nIn this order — the first entries fix the most files.\n\n";
        $position = 0;

        foreach ($result->renameTargets() as $target) {
            $position++;
            $kind = $target['isDirectory'] ? 'folder' : 'file';
            $where = $target['parent'] === ''
                ? 'at the top of `'.$target['area'].'`'
                : 'in `'.self::code($target['parent']).'`';

            $out .= sprintf(
                "%d. **`%s`** — %s %s  \n",
                $position,
                self::code($target['segment']),
                $kind,
                $where,
            );
            $out .= sprintf(
                "   Affects %s. Offending: %s.  \n",
                $target['files'] === 1 ? '1 file' : $target['files'].' files',
                implode(', ', array_map(
                    fn (string $c) => '`'.self::codePoint($c).'`'.(self::isVisible($c) ? ' `'.self::code($c).'`' : ''),
                    $target['offenders'],
                )),
            );

            $reads = self::readsAs($target['segment']);
            if ($reads !== null) {
                $out .= '   Sits here: `'.self::code($reads)."` — those marks are invisible on screen.  \n";
            }

            $out .= '   '.self::suggestion($target['segment'])."\n\n";
        }

        return $out;
    }

    /**
     * The name rewritten with its invisible offenders spelled out, or null when they are all
     * visible anyway.
     *
     * WITHOUT THIS THE ADVICE IS UNFOLLOWABLE. Roughly half of what a real collection turns up
     * cannot be seen: a private-use character a tagger swapped in for `?`, a punctuation space
     * that looks exactly like a space, a combining accent that renders as an ordinary one.
     * Telling someone to fix `01 - Who Was In My Room.mp3` is telling them to find a character
     * that is not on their screen; `01 - Who Was In My Room⟨U+F023⟩.mp3` shows them where to put
     * the cursor.
     */
    private static function readsAs(string $segment): ?string
    {
        $shown = $segment;
        $found = false;

        foreach (PathEncodingAudit::offendersIn($segment) as $character) {
            if (self::isVisible($character)) {
                continue;
            }

            $shown = str_replace($character, '⟨'.self::codePoint($character).'⟩', $shown);
            $found = true;
        }

        return $found ? $shown : null;
    }

    /**
     * A concrete replacement name, when one can be offered honestly.
     *
     * Three outcomes, and the distinction is the useful part. A decomposed accent needs no new
     * name at all — precomposing changes bytes, not glyphs — and saying "rename this" there
     * would send the reader looking for a difference they cannot see. Otherwise each offender is
     * transliterated to ASCII where intl has an equivalent (`ł` → `l`), and where even one does
     * not (CJK, `∞`), no suggestion is made rather than a mangled one.
     */
    private static function suggestion(string $segment): string
    {
        $composed = Normalizer::normalize($segment, Normalizer::FORM_C);

        if (is_string($composed) && $composed !== $segment && PathEncodingAudit::offendersIn($composed) === []) {
            return '*Precompose only (NFC) — the name does not change visibly, only its bytes.*';
        }

        $suggested = $segment;
        foreach (PathEncodingAudit::offendersIn($segment) as $character) {
            $ascii = self::transliterate($character);

            if ($ascii === null) {
                return '*No safe automatic replacement — pick a new name by hand.*';
            }

            $suggested = str_replace($character, $ascii, $suggested);
        }

        return 'Suggested: **`'.self::code($suggested).'`**';
    }

    /** Every affected file, so nothing is hidden behind the grouping above. */
    private static function appendix(PathEncodingAuditResult $result): string
    {
        $out = "### Every affected file\n\n"
            ."The complete list, area by area, in case the grouping above hid something you were\n"
            ."looking for.\n\n";
        $byArea = [];

        foreach ($result->findings as $finding) {
            $byArea[$finding->area->libraryPathKey()][] = $finding;
        }

        foreach ($byArea as $area => $findings) {
            $out .= '#### '.$area."\n\n";
            // Alphabetical, not walk order: this list is diffed against the previous run.
            usort($findings, fn (PathEncodingFinding $a, PathEncodingFinding $b) => strcmp($a->path, $b->path));

            foreach ($findings as $finding) {
                $out .= '- `'.self::code($finding->path).'`'
                    .($finding->precomposeFixes ? ' — *precompose only*' : '')."\n";
            }

            $out .= "\n";
        }

        return $out;
    }

    /** The step that is easy to forget, and whose absence looks exactly like a broken player. */
    private static function afterwards(): string
    {
        return <<<'MD'
        ### After renaming

        Run **`php artisan app:update`**. Until you do, the database still holds the old paths, and
        every file you just renamed is unplayable — the app looks for a name that no longer exists.

        The scan will report the renames as **moved**, not as removed-and-new: identity is the
        audio-frame hash, not the path, so each track keeps its id and its playlists, play counts and
        share links survive the rename. If it reports new and removed instead, stop and look — that
        means a file was not matched, and something else changed with it.

        Then re-run `php artisan app:audit` to confirm the list has emptied.
        MD;
    }

    /**
     * `U+0142` for a character — the only form that is unambiguous in a document like this.
     *
     * `%04X` is a floor, not a width limit: an astral character prints its full five or six
     * digits rather than being truncated to something that means a different character.
     */
    private static function codePoint(string $character): string
    {
        return sprintf('U+%04X', IntlChar::ord($character) ?? 0);
    }

    /** The Unicode name, or an honest stand-in — private-use characters genuinely have none. */
    private static function nameOf(string $character): string
    {
        $name = IntlChar::charName(IntlChar::ord($character) ?? 0);

        return is_string($name) && $name !== '' ? $name : self::UNNAMED;
    }

    /**
     * Whether printing the character raw is safe and useful.
     *
     * A combining mark must never be printed bare: it attaches to whatever precedes it, which in
     * a Markdown table is the cell delimiter, so it would both look wrong and corrupt the table.
     * Private-use and control characters render as nothing or as tofu, which tells the reader
     * less than the dash we print instead.
     */
    private static function isVisible(string $character): bool
    {
        return ! in_array(IntlChar::charType($character), [
            IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
            IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
            IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK,
            IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR,
            IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
            IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
            IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
            IntlChar::CHAR_CATEGORY_LINE_SEPARATOR,
            IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
            IntlChar::CHAR_CATEGORY_UNASSIGNED,
        ], true);
    }

    /**
     * What to do about one character, by what KIND of thing it is.
     *
     * Categories rather than a lookup table of known offenders, so a collection this was never
     * run against still gets useful advice: the four cases below are how these actually arrive —
     * a Mac decomposing an accent, a tagger substituting an illegal character, a word processor
     * inserting a typographic space, and genuinely foreign text.
     */
    private static function advice(string $character): string
    {
        $ascii = self::transliterate($character);

        return match (IntlChar::charType($character)) {
            IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
            IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
            IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK => 'A combining accent — the name is stored '
                .'decomposed, as macOS writes them. Precompose it (NFC) and it encodes fine, looking '
                .'exactly the same.',
            IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR => 'Invisible, and not really a character: almost '
                .'always a tagger\'s stand-in for something a filename may not hold (`?`, `"`, `:`). '
                .'Delete it, or put back what it stood for.',
            IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
            IntlChar::CHAR_CATEGORY_FORMAT_CHAR => 'An exotic space that reads as an ordinary one. '
                .'Replace it with a plain space.',
            default => $ascii === null
                ? 'No Latin equivalent — choose a new name.'
                : 'Replace with `'.$ascii.'`.',
        };
    }

    /**
     * A plain-ASCII stand-in for one character, or null when intl has nothing to offer.
     *
     * Null rather than a best effort on purpose: transliteration returns the empty string for
     * things like `∞`, and quietly deleting a character from a suggested filename is worse than
     * admitting there is no suggestion.
     */
    private static function transliterate(string $character): ?string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $character);

        if (! is_string($ascii) || $ascii === '' || ! preg_match('/^[\x20-\x7E]+$/', $ascii)) {
            return null;
        }

        return $ascii === $character ? null : $ascii;
    }

    /**
     * Prose of our own, made safe for a table cell: pipes escaped, backticks left alone.
     *
     * Kept apart from {@see tableCode} because the advice strings contain deliberate code spans
     * (`` `l` ``, `` `?` ``) and running them through the value-escaper turned every one into a
     * quote mark.
     */
    private static function tableText(string $value): string
    {
        return str_replace('|', '\|', $value);
    }

    /**
     * A value from the collection, printed inside a code span inside a table.
     *
     * A pipe ends the cell wherever it appears — GFM splits rows on pipes BEFORE it parses
     * inline code, so a code span does not protect one, and album titles really do contain them.
     * `\|` is the documented escape and works in that position; backticks would close the span,
     * so they become quotes.
     */
    private static function tableCode(string $value): string
    {
        return str_replace(['|', '`'], ['\|', "'"], $value);
    }

    /**
     * A value from the collection, printed inside a code span in ordinary prose.
     *
     * Pipes are deliberately NOT escaped here: outside a table they need no escaping, and a
     * backslash inside a code span is not an escape at all — it would print literally, so the
     * table-safe form would corrupt every path containing a pipe.
     */
    private static function code(string $value): string
    {
        return str_replace('`', "'", $value);
    }
}
