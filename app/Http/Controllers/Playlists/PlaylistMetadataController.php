<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\EditPlaylistRequest;
use App\Http\Requests\Playlists\StorePlaylistRequest;
use App\Http\Requests\Playlists\UpdatePlaylistRequest;
use App\Models\Playlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A playlist's METADATA — the two things it owns of its own, a name and a blurb —
 * created at `GET /playlists/create` + `POST /playlists` and edited at
 * `GET /playlists/{playlist}/edit` + `PUT /playlists/{playlist}`.
 *
 * FOUR ACTIONS ON ONE CONTROLLER, and one page behind both pairs, because create and
 * edit differ in almost nothing: the same two fields, the same rules, the same
 * messages. Split across two controllers they would drift — the `max` that only one
 * side enforces, the trim that only one side does — and the drift would be invisible
 * until a reader hit it.
 *
 * NOTHING HERE VALIDATES OR AUTHORIZES. Both live in the request classes
 * (App\Http\Requests\Playlists), which is what keeps these actions down to what they
 * actually do: rules and messages in ValidatesPlaylistMetadata, ownership in
 * AuthorizesPlaylistOwnership, and Precognition handled by the framework — a FormRequest
 * filters its own rules and short-circuits with a 204 on a validate-only request, so the
 * `precognitive()` wrapper is redundant here.
 *
 * A playlist starts EMPTY, and editing never touches its tracks. They are added from
 * wherever a listener already is — a song page, an album, the queue — so the form asks
 * only for name and description in both directions, and `position` is left alone on
 * update (a rename is not a reorder).
 */
class PlaylistMetadataController extends Controller
{
    /** Render the "new playlist" form. No playlist to seed it with, which is what tells the page it is creating. */
    public function create(): Response
    {
        return Inertia::render('Playlists/Metadata/PlaylistMetadataPage', ['playlist' => null]);
    }

    /** Create the playlist, then land the user back on their listing. */
    public function store(StorePlaylistRequest $request): RedirectResponse
    {
        try {
            $playlist = $this->insert($request);
        } catch (UniqueConstraintViolationException) {
            // The `unique` rule and the INSERT are not one atomic step, so two submits
            // in flight together (a double-click, a retried request) can both pass
            // validation and only one can land. Answer the loser with the same field
            // error it would have got a moment earlier, rather than a 500.
            throw $this->nameTaken();
        }

        return $this->done($request, 'flash.playlist.created', $playlist);
    }

    /**
     * Render the edit form over an existing playlist's metadata.
     *
     * The playlist goes over as a prop rather than being fetched by the page: the page
     * has no way to ask, and the request has already loaded the row to check who owns it.
     */
    public function edit(EditPlaylistRequest $request, Playlist $playlist): Response
    {
        return Inertia::render('Playlists/Metadata/PlaylistMetadataPage', [
            'playlist' => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'description' => $playlist->description,
            ],
        ]);
    }

    /** Save the edited metadata, then land the user back on their listing. */
    public function update(UpdatePlaylistRequest $request, Playlist $playlist): RedirectResponse
    {
        try {
            // `position` is untouched on purpose — a rename is not a reorder. And the
            // fields are already trimmed: the request cleans them before its own rules run,
            // so what `validated()` hands back is exactly what should be stored.
            $playlist->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }

        return $this->done($request, 'flash.playlist.updated', $playlist);
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
     * by asking a real Postgres.
     */
    private function insert(StorePlaylistRequest $request): Playlist
    {
        $user = $request->user();
        $last = Playlist::query()->where('user_id', $user->id)->max('position');

        return $user->playlists()->create($request->validated() + [
            'position' => $last === null ? 0 : ((int) $last) + 1,
        ]);
    }

    /**
     * The field error a lost unique race gets, so it reads the same as the rule's own.
     *
     * A ValidationException rather than `back()->withErrors()`: it IS a validation failure,
     * and only the exception renders as a 422 JSON body where the caller asked for one.
     */
    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => __('playlist.validation')['name.unique']]);
    }

    /** Flash the outcome and send the reader back to their listing. */
    private function done(Request $request, string $key, Playlist $playlist): RedirectResponse
    {
        $request->session()->flash('message', __($key, ['name' => $playlist->name]));
        $request->session()->flash('type', 'success');

        return redirect()->route('playlists');
    }
}
