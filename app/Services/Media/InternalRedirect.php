<?php

namespace App\Services\Media;

use App\Enums\TrackType;

/**
 * "Let nginx send this file" — the `X-Accel-Redirect` URI for one media file, or null
 * when there is no nginx in front to hand off to.
 *
 * Streaming a 96 GB collection through php-fpm would tie up a worker for the whole
 * length of every song or download, and there are only so many workers; on the live box
 * nginx serves the bytes from an `internal;` location and PHP is free again the moment
 * the header is written. See `docs/self-hosting/files/mixtape.prod.nginx.conf`.
 *
 * It lives here rather than in the controller that first needed it because a SECOND
 * route now sends the same bytes (the song download beside the stream), and the two
 * rules below are exactly the ones that cost this project a day each when they were got
 * wrong — neither is a thing to have two copies of.
 */
final class InternalRedirect
{
    /**
     * The redirect target for an area-relative path, or null to send the file directly.
     *
     * NULL WHENEVER THE PREFIX IS BLANK, and "blank" includes whitespace. `.env` ships
     * this key empty, and an empty dotenv value arrives as an empty STRING, not null: a
     * `=== null` check therefore turned the hand-off ON with no prefix, which pointed
     * `X-Accel-Redirect` at a URI nothing serves, bounced it back through `try_files`
     * into index.php, and cost every stream a 500 with an nginx "internal redirection
     * cycle" and nothing at all in Laravel's log. Empty means "no nginx in front" — the
     * same rule an unconfigured library area follows (LibraryScanService::scanArea).
     *
     * The URI is built from the AREA KEY rather than by subtracting the media root from
     * an absolute path, so there is no prefix arithmetic to get wrong: each area gets its
     * own `internal;` location whose `alias` is that area's `MIXTAPE_*_PATH`, and the key
     * naming them is the one `config('mixtape.library.paths.*')` already uses.
     *
     * Every segment is `rawurlencode`d because nginx URL-DECODES the redirect target:
     * this collection is full of spaces, umlauts, `#` and `&` in file names, and an
     * unencoded `#` would truncate the path at the fragment and 404 a track that plays
     * perfectly well over the direct route. The slashes stay slashes — they are the
     * directory structure.
     */
    public static function uriFor(string $areaRelativePath, TrackType $type): ?string
    {
        $prefix = trim((string) config('mixtape.stream.internal_prefix'));

        if ($prefix === '') {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($areaRelativePath, '/'))));

        return rtrim($prefix, '/').'/'.$type->libraryPathKey().'/'.$encoded;
    }
}
