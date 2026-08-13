<?php

namespace App\Http\Requests\Audiobooks;

use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobookChapter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards a chapter's cover art (`GET /audiobooks/chapters/{chapter}/cover`).
 *
 * Authorization only; the rule is in AuthorizesAudiobookChapter.
 */
class ChapterCoverRequest extends FormRequest
{
    use AuthorizesAudiobookChapter;
}
