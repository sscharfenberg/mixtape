<?php

namespace App\Http\Requests\Audiobooks;

use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobookChapter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards a chapter's audio (`GET /audiobooks/chapters/{chapter}/stream`).
 *
 * Authorization only — a GET carries no fields. The rule itself, why the route is flat
 * rather than nested under its book, and why it answers 404 rather than 403, are in
 * AuthorizesAudiobookChapter.
 */
class ChapterStreamRequest extends FormRequest
{
    use AuthorizesAudiobookChapter;
}
