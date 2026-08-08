<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Making a playlist: the form (`GET /playlists/create`) and the create itself
 * (`POST /playlists`). Two actions on one controller rather than two invokables,
 * the same shape the auth forms use (Auth\ForgotController) — the page and the
 * submit it posts to share their validation rules, and splitting them across files
 * is how the two drift apart.
 *
 * A playlist starts EMPTY. Tracks are added from wherever a listener already is —
 * a song page, an album, the queue — not by picking them out of a modal at create
 * time, so this form asks only for the two things a playlist has of its own.
 */
class CreatePlaylistController extends Controller
{
    /** Render the "new playlist" form. Nothing to seed it with — a new playlist has no state. */
    public function show(): Response
    {
        return Inertia::render('Playlists/Create/CreatePlaylistPage');
    }

    /**
     * Validate and create the playlist, then land the user back on their listing.
     *
     * Precognitive so the form can live-validate a single field on blur — which is
     * what makes the per-user name check useful: "you already have a playlist called
     * that" is worth hearing before the whole form is submitted.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        precognitive(function () use ($request, $user) {
            $request->validate(
                $this->rules($user),
                // Inline messages, because `validation.custom.name.*` is written for the
                // USERNAME field ("This username is already taken") — every auth form in
                // the app posts a `name` and means the login id. Inline messages beat
                // `validation.custom` in the resolver, so these win here and nowhere else.
                __('playlist.validation'),
                __('playlist.attributes'),
            );
        });

        try {
            $playlist = $this->create($request, $user);
        } catch (UniqueConstraintViolationException) {
            // The `unique` rule and the INSERT are not one atomic step, so two submits
            // in flight together (a double-click, a retried request) can both pass
            // validation and only one can land. Answer the loser with the same field
            // error it would have got a moment earlier, rather than a 500.
            throw ValidationException::withMessages([
                'name' => __('playlist.validation')['name.unique'],
            ]);
        }

        $request->session()->flash('message', __('flash.playlist.created', ['name' => $playlist->name]));
        $request->session()->flash('type', 'success');

        return redirect()->route('playlists');
    }

    /**
     * The field rules, in one place so the form's live validation and the real submit
     * can never disagree about them.
     *
     * `name` is unique PER OWNER — the same composite the migration enforces, so the
     * rule and the constraint say the same thing. `description` is a `text` column
     * with no length of its own; 1000 characters is a blurb, and an unbounded textarea
     * is a free megabyte in someone's database.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(User $user): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('playlists', 'name')->where('user_id', $user->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Insert the playlist at the end of the user's own ordering.
     *
     * Read-then-write on `position`, and DELIBERATELY unguarded. Two creates racing can
     * both read the same max and claim the same slot — which costs nothing: `position` is
     * not unique (the migration says so), the listing tiebreaks on `name`, and a shared
     * position is already the everyday state, since the column defaults to 0 for every
     * playlist made before anyone reorders anything. A reorder renumbers the whole set.
     *
     * It is not merely unnecessary but unavailable: `lockForUpdate()` here compiles to
     * `select max(position) … for update`, which PostgreSQL rejects outright ("FOR UPDATE
     * is not allowed with aggregate functions"). Production is Postgres and the PHP suite
     * runs on sqlite, where `for update` compiles to nothing at all — so the lock read as
     * a green safety measure locally while 500-ing every create on the real server. Found
     * by asking the dev database, 2026-08-08.
     */
    private function create(Request $request, User $user): Playlist
    {
        $last = Playlist::query()->where('user_id', $user->id)->max('position');

        return $user->playlists()->create([
            'name' => $request->string('name')->trim()->value(),
            // An empty textarea posts "", which is not the same as "no description":
            // stored as null so the page can ask one question ("is there a description?")
            // instead of two.
            'description' => $request->string('description')->trim()->value() ?: null,
            'position' => $last === null ? 0 : ((int) $last) + 1,
        ]);
    }
}
