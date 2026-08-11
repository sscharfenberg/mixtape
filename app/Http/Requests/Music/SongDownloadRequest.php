<?php

namespace App\Http\Requests\Music;

use App\Http\Requests\Music\Concerns\AuthorizesMusicTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards the song's file as a download (`GET /music/songs/{song}/download`).
 *
 * The gate is deliberately the same as the page's: whoever may LOOK at a song may keep a
 * copy of it. Nothing is added here beyond being signed in — the route group does that —
 * because a rule this route did not share with SongController would mean a page offering
 * a button that answers 403.
 *
 * A SHARE LINK DOES NOT WIDEN IT, which is the opposite of what this said until 2026-08-11:
 * a share grants LISTENING and nothing else, and the `/s/` space has no counterpart to
 * either download route (docs/sharing.md → "What a share is"). "Listen to this" and "here
 * is the file" are different acts, and only the first one is being asked for.
 *
 * Authorization only; a GET carries no fields. The rule itself, and why it answers 404
 * rather than 403, is in AuthorizesMusicTrack.
 */
class SongDownloadRequest extends FormRequest
{
    use AuthorizesMusicTrack;
}
