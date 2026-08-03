<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Library areas — where the collection lives on disk
    |--------------------------------------------------------------------------
    |
    | One absolute server path per area. The scanner (`php artisan app:update`)
    | walks each of these, and the cleanup step deletes OS/Samba junk from them
    | before anything is analysed. On the live box the media sits under
    | `/var/media`; the per-area env overrides let a dev machine point elsewhere.
    |
    | `podcast_shows` is a v2 addition — the legacy app only had music and
    | audiobooks. The keys here line up with App\Enums\TrackType::libraryPathKey().
    |
    | No baked-in defaults on purpose: an area's path is whatever `.env` says, so
    | all three behave the same. Empty OR absent → the area is disabled (app:update
    | skips it, touching no rows). A non-empty path that isn't a directory IS a
    | failure (a typo or a dropped mount) — the scan aborts and alerts rather than
    | risk orphan-deleting the area. `.env.example` ships the live `/var/media`
    | paths as the template; set them per environment.
    |
    */

    'library' => [
        'paths' => [
            'music' => env('MIXTAPE_MUSIC_PATH'),
            'audiobooks' => env('MIXTAPE_AUDIOBOOKS_PATH'),
            'podcast_shows' => env('MIXTAPE_PODCAST_SHOWS_PATH'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan behaviour
    |--------------------------------------------------------------------------
    */

    'scan' => [

        // Audio file extensions the scanner picks up (matched case-insensitively).
        // Legacy scanned `*.mp3` only; kept configurable for m4b/flac later.
        'extensions' => ['mp3'],

        // Junk files that macOS / Windows / Samba clients scatter through the
        // shares. Deleted (recursively, case-insensitively) BEFORE any analysis
        // so they can't be mistaken for media or dirty a directory listing.
        // Ported from the legacy `collection.server.to_delete`.
        'cleanup_masks' => [
            'Thumbs.db',    // Windows thumbnail cache
            '._*',          // macOS AppleDouble resource forks
            'AlbumArt*',    // Windows Media Player art cache
            '*.gp5',        // Guitar Pro tab files
            '.DS_Store',    // macOS Finder metadata
            '.@__*',        // Samba / netatalk temp files
            '.smbdelete*',  // Samba deferred-delete temp files
        ],

        // Where a fatal, run-aborting scan error is e-mailed (the command exits
        // non-zero and logs to the `library` channel regardless). Null → log
        // only, no e-mail. Set MIXTAPE_SCAN_ALERT_EMAIL on the live box.
        'alert_email' => env('MIXTAPE_SCAN_ALERT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio streaming
    |--------------------------------------------------------------------------
    |
    | How the player's <audio> gets its bytes (SongStreamController).
    |
    | `internal_prefix` NON-EMPTY → the controller answers with an empty body plus
    | an `X-Accel-Redirect` and nginx serves the file from an `internal;` location.
    | That is the only sane arrangement on the live box: streaming a 96 GB
    | collection through php-fpm ties up a worker for the whole length of every
    | song, and there are only so many workers. nginx also handles HTTP Range
    | natively, which is what makes dragging the timeline work.
    |
    | EMPTY or absent (the default, and every dev machine / the test suite) → PHP
    | sends the file itself, with Symfony answering Range requests. There is no nginx
    | to hand off to under `php -S`, and one blocked worker costs nothing locally.
    |
    | Blank and absent MUST behave identically, and the consumer is what guarantees
    | it: a blank `.env` line arrives here as an empty STRING, not null, so
    | SongStreamController guards with `trim((string) …) === ''` — the same rule an
    | unconfigured library area follows above. A `=== null` check here read the
    | shipped blank line as "configured" and 500'd every stream on the dev site.
    |
    | The prefix is a URI, not a path, and each AREA hangs off it under the same key
    | `library.paths` uses — so `/internal-media` expects
    |
    |     location /internal-media/music/ { internal; alias /var/media/music/; }
    |
    | with the alias matching MIXTAPE_MUSIC_PATH. The installable vhost in
    | docs/self-hosting/files/mixtape.prod.nginx.conf ships those locations.
    |
    */

    'stream' => [
        'internal_prefix' => env('MIXTAPE_STREAM_INTERNAL_PREFIX'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cover art
    |--------------------------------------------------------------------------
    |
    | Covers are NOT stored in the database (the scanner only records whether a
    | file has one, as `tracks.cover`) — they are extracted on first request and
    | cached as JPEGs, the way the legacy CoverService did it. Two sources: the
    | audio file's own embedded picture, and an image sitting beside it in the
    | album directory. A SONG prefers its own embedded picture; an ALBUM prefers
    | the directory image (CoverService::albumPath says why).
    |
    | `width` is the long edge the cached copy is scaled down to — a 1400px
    | booklet scan is a needless megabyte on a detail page. Images smaller than
    | that are cached as-is rather than upscaled.
    |
    */

    'covers' => [

        // Directory images to look for beside the audio file, in this ORDER — the
        // first that matches wins. Matched CASE-INSENSITIVELY, and that is the whole
        // reason this is a list of lower-case names rather than the single
        // "Folder.jpg" it started as (legacy `collection.coverFile.name`, the name
        // Windows Media Player is documented to write). Measured against the real
        // collection: of 951 album directories, 923 hold `folder.jpg` in lower case
        // and exactly ONE holds the capitalised spelling — so on a case-sensitive
        // filesystem the old exact-name lookup found art in 1 directory out of 951.
        // It went unnoticed because 12051 of 12060 files carry embedded art, which is
        // checked first for a song and so almost never reaches this list.
        //
        // The order matters where a directory holds several images: 63 of them have
        // `cover.jpg` beside `folder.jpg`, and the collection also contains
        // `back.jpg`, `cd.jpg`, `inlay.jpg` and `booklet.jpg` — a back cover or a
        // disc scan must never become an album's thumbnail by sorting earlier than
        // the front. Names NOT on this list are therefore only ever used when the
        // directory holds exactly one image (see CoverService::directoryImage).
        'folder_images' => [
            'folder.jpg',
            'cover.jpg',
            'front.jpg',
            'folder.png',
            'cover.png',
            'front.png',
        ],

        // Long edge (px) of the cached copy, and its JPEG quality.
        'width' => 450,
        'quality' => 82,
    ],

];
