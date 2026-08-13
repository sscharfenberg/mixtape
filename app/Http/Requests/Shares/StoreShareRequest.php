<?php

declare(strict_types=1);

namespace App\Http\Requests\Shares;

use App\Enums\CollectionType;
use App\Enums\ShareSubject;
use App\Enums\TrackType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * "Mint me a link to this" — the body of `POST /shares`, pressed from a detail page's hero.
 *
 * ONE SHAPE: `{ subject: "album", id: "<uuid>" }`, the same pair a detail page already
 * sends to add its subject to a playlist (AddTracksToPlaylistRequest's first shape). What
 * may be named is {@see ShareSubject} and nothing else, which is where "no genre share" is
 * actually enforced — a body naming one fails the enum rule rather than reaching a controller
 * that would have to know about it.
 *
 * WHAT MAY BE SHARED IS DECIDED BY THE RULES, NOT BY `authorize()`, and that stays true now
 * that a PLAYLIST can be shared (2026-08-13) — the one subject with an owner, where the
 * library belongs to every account alike. Its ownership is a `where` on the `exists` rule
 * rather than a check of its own, because it is the same KIND of question the two type
 * narrowings below already answer: "could the page that sent this have shown it to you?" A
 * stranger's playlist id then fails validation exactly as a made-up one does, which is also
 * the disclosure posture the rest of the feature keeps (DestroyShareRequest's 404) — a
 * dedicated 403 would confirm that the id names somebody's real playlist. The route group
 * supplies `auth`.
 *
 * THE ID IS CHECKED FOR EXISTENCE, unlike the track ids the play queue sends, because there
 * is exactly one of it and it is about to be written as a foreign key. The `exists` rule also
 * narrows on TYPE, which is the half that is easy to leave out: `tracks` and `collections`
 * are unified tables holding audiobook chapters and audiobooks beside music, so an
 * audiobook's id passed as `subject: "album"` would otherwise mint a share for something the
 * album page could never have offered — the same trap AuthorizesMusicTrack exists for.
 */
class StoreShareRequest extends FormRequest
{
    /**
     * The subject kind, and an id that really is one of those.
     *
     * `rules()` reads the submitted subject to pick the `exists` rule, which is safe because
     * the enum rule beside it rejects anything else: a body naming a subject this app does
     * not share never reaches the branch, and `null` there falls back to a shape-only check
     * so a bad subject is reported as a bad SUBJECT rather than as a missing row.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $subject = ShareSubject::tryFrom((string) $this->input('subject'));

        return [
            'subject' => ['required', Rule::enum(ShareSubject::class)],
            'id' => array_filter(['required', 'uuid', $subject ? $this->subjectExists($subject) : null]),
        ];
    }

    /**
     * Where the id must be found, narrowed to the kind the page that sent it shows.
     *
     * An artist needs no narrowing — `artists` holds nothing else. The other three do: two on
     * `type`, because those tables are shared with audiobooks, and a PLAYLIST on `user_id`,
     * because it is the one subject that belongs to somebody. The class note says why that is
     * a rule rather than an `authorize()`.
     *
     * NOT called `exists()`: `Illuminate\Http\Request` already has a public method of that
     * name (does the input hold this key?), and a private override of it is a fatal error at
     * CLASS-LOAD time — which surfaces as the router failing to reflect on the action, a
     * stack trace with nothing about this file near the top of it.
     */
    private function subjectExists(ShareSubject $subject): ValidationRule|Exists
    {
        $rule = Rule::exists($subject->table(), 'id');

        return match ($subject) {
            ShareSubject::Song => $rule->where('type', TrackType::Music->value),
            ShareSubject::Album => $rule->where('type', CollectionType::Album->value),
            ShareSubject::Artist => $rule,
            // `user()` cannot be null here — the route group is behind `auth` — and a guest
            // never reaches validation at all, so this is the signed-in reader's own id.
            ShareSubject::Playlist => $rule->where('user_id', $this->user()->id),
        };
    }
}
