<?php

namespace App\Http\Controllers;

use App\Models\ExportPreset;
use App\Models\Playlist;
use App\Services\Playlists\ExportPresetRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Playlists area (`GET /playlists`, route `playlists`, behind auth) — the
 * reader's OWN saved playlists and nothing else. Single action, so it's invokable.
 *
 * Scoped to the current user at the query, not filtered afterwards: playlists are
 * private per owner (the `(user_id, name)` unique in the migration exists precisely
 * because "your Rock ≠ my Rock"), so a listing that fetched everything and hid the
 * rest would be one forgotten filter away from leaking another account's library.
 *
 * A plain list rather than a DataTable — a person has a handful of playlists, not a
 * thousand, so there is nothing to sort, search or paginate yet. When that changes
 * this becomes a DataTableService call like the Music listings.
 */
class PlaylistsController extends Controller
{
    /**
     * Render the reader's playlists.
     *
     * The two numbers are AGGREGATES over the entries, computed in this one query: how
     * many tracks (counted through the pivot) and how long they play (summed off the
     * joined tracks). Loading the tracks themselves to count and total them in PHP would
     * read a large part of the library to print two figures per row.
     */
    public function __invoke(Request $request): Response
    {
        $playlists = Playlist::query()
            ->where('user_id', $request->user()->id)
            ->withCount('playlistTracks as tracks_count')
            // Raw seconds, exactly as a single track's duration goes over — the page
            // clocks it. Null for an empty playlist, which SQL's SUM of no rows gives.
            ->withSum('tracks as duration', 'duration')
            // The user's own ordering first; `name` breaks the tie, because `position`
            // defaults to 0 and every playlist made before any reordering shares it.
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return Inertia::render('Playlists/PlaylistsPage', [
            // The export dialog is reachable from here as well as from a playlist's own page
            // (its menu, and the "export all" button), so this page carries the two things that
            // dialog opens with: the prefix a reader with no presets falls back to, and their
            // presets themselves.
            'exportPrefix' => (string) config('mixtape.playlists.export.path_prefix'),
            'exportPresets' => ExportPresetRows::for(
                ExportPreset::query()->where('user_id', $request->user()->id)->inReadingOrder()->get()
            ),
            // THE PATHS THE WINDOWS-1252 WARNING IS COMPUTED FROM, and the one prop here that is
            // OPTIONAL — Inertia evaluates it only for a partial reload that asks for it by name.
            //
            // The warning has to name the tracks whose paths that encoding cannot carry, which
            // means having the paths; the detail page already holds them for its table, and this
            // page holds nothing but aggregates. Sending them with every visit would put a few
            // hundred strings on a page a reader opens constantly to serve a dialog they open
            // rarely — and computing the answer server-side instead would mean a second
            // implementation of Utils/encoding's table, which is the kind of pair that drifts
            // apart silently. So the listing asks for them at the moment a dialog opens
            // (`router.reload({ only: ["exportPaths"] })`), and the warning appears when they
            // land, which is still before the reader can have chosen Windows-1252.
            'exportPaths' => Inertia::optional(fn (): array => $this->pathsByPlaylist($request)),
            'playlists' => $playlists->map(fn (Playlist $playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'description' => $playlist->description,
                'tracks' => (int) $playlist->tracks_count,
                'duration' => $playlist->duration === null ? null : (float) $playlist->duration,
                // Raw ISO-8601, formatted on the page against the viewer's locale and
                // timezone — the server cannot know either (Utils/formatting.ts).
                'createdAt' => $playlist->created_at?->toIso8601String(),
                // NULL for a playlist nothing has happened to since, so the page asks one
                // question ("is there an updatedAt?") instead of comparing two timestamps
                // — the same shape `description` uses. Whether a row changed is a fact
                // about the data, which is the server's half; how a date reads is the
                // page's. Both columns are written from one instant on insert, so they are
                // exactly equal until something moves one of them (PlaylistTrack::$touches
                // is what makes adding or removing a track count as a change).
                'updatedAt' => $playlist->updated_at === null || $playlist->updated_at->equalTo($playlist->created_at)
                    ? null
                    : $playlist->updated_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Every track's title and path, grouped by the playlist holding it — what the export
     * dialog's Windows-1252 warning reads.
     *
     * ONE QUERY FOR EVERY PLAYLIST rather than one per dialog, because "export all" needs the
     * lot and a per-playlist endpoint would then be a dozen round trips. Grouped here rather
     * than on the client so the caller can hand the dialog exactly the playlists it covers.
     *
     * A query builder rather than the relation: this reads two columns per row and would
     * otherwise hydrate a Track model for each of them. Ordered like the export itself, so the
     * names in a warning appear in the order the file would have listed them.
     *
     * @return array<string, list<array{name: string, path: string}>>
     */
    private function pathsByPlaylist(Request $request): array
    {
        return DB::table('playlist_tracks')
            ->join('tracks', 'tracks.id', '=', 'playlist_tracks.track_id')
            ->join('playlists', 'playlists.id', '=', 'playlist_tracks.playlist_id')
            ->where('playlists.user_id', $request->user()->id)
            ->orderBy('playlist_tracks.position')
            ->orderBy('playlist_tracks.id')
            ->select(['playlist_tracks.playlist_id', 'tracks.name', 'tracks.path'])
            ->get()
            ->groupBy('playlist_id')
            ->map(fn ($rows): array => $rows
                ->map(fn ($row): array => ['name' => $row->name, 'path' => $row->path])
                ->all())
            ->all();
    }
}
