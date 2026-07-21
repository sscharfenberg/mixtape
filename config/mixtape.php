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

];
