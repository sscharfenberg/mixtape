<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Builds the `Content-Disposition` header for a download.
 *
 * Shared by every route that answers with a file rather than a page — the playlist
 * export, one song's mp3, an album's .zip — because all three interpolate a name that
 * came from the collection or from a reader into a header, and getting that wrong is
 * the same mistake three times: a quote or a newline breaks the header, a slash makes it
 * a path, and a `%` in the ASCII fallback is rejected outright by the builder below.
 *
 * The name goes through BOTH parameters: `filename` for clients that read only ASCII,
 * `filename*` (RFC 5987) for the rest, which is what carries an umlaut in an album title
 * intact. Symfony's helper builds the pair — it escapes the ASCII fallback and
 * percent-encodes the UTF-8 one, which is exactly the pair of mistakes a concatenated
 * header makes.
 */
trait SendsAttachments
{
    /**
     * The header value for `$filename`, with an ASCII fallback derived from it.
     *
     * Everything outside printable ASCII becomes an underscore in the fallback, and so
     * does `%` — HeaderUtils rejects a fallback containing one, since a reader cannot
     * tell it apart from the start of a percent-escape. Path separators are replaced in
     * BOTH spellings: a song download names the file on disk, and a Linux filename may
     * legally contain a backslash.
     *
     * The substitution is per CHARACTER, not per byte — `Härz.mp3` becomes `H_rz.mp3`
     * rather than `H__rz.mp3`, since an umlaut is two bytes of UTF-8. It falls back to a
     * byte-wise pass for a name that is not valid UTF-8 at all, which this collection
     * really does contain: PathEncodingAudit exists because some directories were written
     * over Samba in an 8-bit encoding, and `preg_replace` with `/u` returns null on those
     * rather than a string.
     *
     * `$fallback` is only reached when the name reduces to nothing at all — an mp3
     * called `ä.mp3` still yields `_.mp3`, so this is about the empty string rather than
     * about unusual alphabets.
     */
    protected function attachment(string $filename, string $fallback = 'download'): string
    {
        $safe = str_replace(['/', '\\'], '_', $filename);
        $ascii = preg_replace('/[^\x20-\x7E]|%/u', '_', $safe)
            ?? preg_replace('/[^\x20-\x7E]|%/', '_', $safe)
            ?? '';

        if (trim($ascii) === '') {
            $ascii = $fallback;
        }

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $safe, $ascii);
    }
}
