/******************************************************************************
 * encoding
 * Which characters a Windows-1252 file can actually carry — asked in the browser, so the
 * export modal can warn BEFORE the download rather than leaving the reader to find out in
 * the car.
 *
 * WHY THIS EXISTS AT ALL. An .m3u's path lines have to name real files. Windows-1252 covers
 * about 250 characters, and anything outside them is substituted with "?" on the way out —
 * which is not a cosmetic loss on a path line, it is a DEAD line: "?" is not even a legal
 * filename character on FAT, so the player looks for a file that cannot exist. No substitute
 * character fixes that, and neither does transliteration; if a filename contains a character
 * Windows-1252 lacks, no byte sequence in a Windows-1252 file can name it. The only honest
 * response is to say which tracks will be missing.
 *
 * Measured against the real collection (2026-08-09): 89 of 12,074 paths, and they CLUSTER —
 * 27 for one Taiwanese band, 23 for Mgła, 10 for a Godspeed record. So it is not 0.7% of
 * every playlist, it is none of most playlists and all of a few, which is exactly the shape
 * that makes a warning worth showing.
 *
 * WHY A TABLE AND NOT AN ENCODER. `TextEncoder` only speaks UTF-8 — the platform will decode
 * Windows-1252 but never encode it — so representability has to be decided from the code
 * point. The set below is the WHATWG windows-1252 index, which is what PHP's mbstring
 * implements on the server, so the two agree about what survives.
 *****************************************************************************/

/**
 * The 27 characters Windows-1252 puts in 0x80–0x9F, where Latin-1 keeps control codes.
 *
 * This block is the whole reason a naive "is it Latin-1?" test is wrong in both directions:
 * a curly quote, an em dash, a euro sign and an ellipsis all survive the trip and would be
 * flagged by one, while a plain C1 control would not.
 */
const CP1252_EXTRAS = new Set([
    0x20ac, 0x201a, 0x0192, 0x201e, 0x2026, 0x2020, 0x2021, 0x02c6, 0x2030, 0x0160, 0x2039,
    0x0152, 0x017d, 0x2018, 0x2019, 0x201c, 0x201d, 0x2022, 0x2013, 0x2014, 0x02dc, 0x2122,
    0x0161, 0x203a, 0x0153, 0x017e, 0x0178
]);

/**
 * Whether one code point survives a trip through Windows-1252.
 *
 * ASCII and the printable Latin-1 upper half pass, plus the 27 above. U+0080–U+009F are
 * treated as unrepresentable, which is a deliberate approximation in the safe direction:
 * mbstring does map five of them, but a C1 control in a filename is broken long before an
 * encoding gets to it.
 */
const survives = (code: number): boolean =>
    code <= 0x7f || (code >= 0xa0 && code <= 0xff) || CP1252_EXTRAS.has(code);

/**
 * The characters in `text` that Windows-1252 cannot carry, de-duplicated and in the order
 * they first appear.
 *
 * Returned rather than a bare boolean because naming them is what makes the warning
 * actionable — "ł" tells the reader which record and what to rename; "this file will not
 * play" leaves them guessing. Iterated with the spread, which walks CODE POINTS: `for` over
 * indices would split an emoji or a CJK ideograph outside the BMP into two halves and report
 * a pair of meaningless surrogates.
 */
export function unencodableInWindows1252(text: string): string[] {
    const found: string[] = [];

    for (const character of [...text]) {
        const code = character.codePointAt(0) ?? 0;

        if (!survives(code) && !found.includes(character)) {
            found.push(character);
        }
    }

    return found;
}

/** Whether `text` survives Windows-1252 whole — the boolean form of the above. */
export const isWindows1252 = (text: string): boolean => unencodableInWindows1252(text).length === 0;
