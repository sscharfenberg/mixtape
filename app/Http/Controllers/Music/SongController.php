<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\Media\CoverService;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One song's detail page (`GET /music/songs/{song}`, route `music.songs.show`,
 * behind auth) — the target of a row click in the Songs listing (SongsController
 * puts this URL on every row as `href`).
 *
 * Sibling to SongsController by design: same `Music` namespace, singular name for
 * the single-record view, so the pair reads like the routes do (`music.songs` /
 * `music.songs.show`).
 *
 * It passes every stored fact about the file — tags, position in the album, the
 * technical stream fields, size/mtime/path — as RAW values, with no formatting at
 * all: sizes, rates, durations and timestamps all read differently per language,
 * so the page formats them against the viewer's locale (Utils/formatting.ts, the
 * same split MusicController uses for the stats widget).
 *
 * Cover art is the one prop that is NOT a stored fact: `coverUrl` points at
 * SongCoverController, which extracts the image from the file on first request.
 *
 * Still to come here (docs/app-rewrite.md): the player, play history and the
 * "also appears in N other places" clone list.
 */
class SongController extends Controller
{
    /**
     * Render one song. `{song}` resolves through implicit binding on the Track
     * UUID, so an unknown id is a 404 before this runs.
     *
     * Tracks are one table for music, audiobook chapters and (future) podcast
     * episodes, so a bare binding would happily serve an audiobook chapter under
     * /music/songs/… — the type check is what keeps this route about music.
     */
    public function __invoke(Track $song, CoverService $covers): Response
    {
        abort_unless($song->type === TrackType::Music, 404);

        // Eager-loaded rather than lazily touched in the array below, so the page
        // costs a fixed four queries no matter how much the scaffold grows.
        $song->load(['artist:id,name', 'collection:id,name,year', 'genre:id,name']);

        $totals = $this->collectionTotals($song);

        return Inertia::render('Music/Songs/Song/SongPage', [
            'song' => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
                'album' => $song->collection?->name,
                // Where these names LEAD — the same shape a DataTable row's `href` takes,
                // and here for the same reason: the server owns which links exist, so the
                // page renders a link when it is handed one and plain text when it is not.
                // Each is null when the tag was missing (a song filed under no album, a
                // file crediting no performer).
                'albumUrl' => $song->collection_id === null
                    ? null
                    : route('music.albums.show', $song->collection_id, absolute: false),
                'artistUrl' => $song->artist_id === null
                    ? null
                    : route('music.artists.show', $song->artist_id, absolute: false),
                'genre' => $song->genre?->name,
                // The genre gets no URL yet: it is the last of the three names whose area
                // is still a listing with no detail page behind it. When one lands, this
                // grows a `genreUrl` beside the two above and the page changes nowhere
                // else.
                'year' => $song->collection?->year,
                'composer' => $song->composer,
                'publisher' => $song->publisher,
                'duration' => $song->duration, // seconds; the page clocks it to m:ss

                // Position in the album, numerator + denominator apart, so the page can
                // render "2/8" — or just "2" where the total isn't trustworthy. Both
                // totals are COMPUTED here (see totalsFor), not stored, so a
                // single-disc album reports discTotal 1 rather than null; the page
                // shows that "1/1" deliberately.
                'track' => $song->track,
                'trackTotal' => $totals['tracks'],
                'disc' => $song->disc,
                'discTotal' => $totals['discs'],

                // Technical stream fields, as the scanner read them from the mp3.
                // `channel` goes over as the enum's raw value (e.g. joint_stereo)
                // and is translated client-side via `music.channel.*`.
                'codec' => $song->codec,
                'channel' => $song->channel?->value,
                'sampleRate' => $song->sample_rate,
                'bitRate' => $song->bit_rate,
                'vbr' => $song->vbr,
                'cover' => $song->cover,

                // The hero's <img> source, or null when neither an embedded
                // picture nor a Folder.jpg exists — decided HERE (one cheap stat,
                // no extraction) so the page can render its placeholder instead
                // of pointing an <img> at a 404.
                'coverUrl' => $covers->exists($song) ? route('music.songs.cover', $song) : null,

                // The file itself. `path` is area-relative (never the absolute
                // server path — Track's docblock), which is also what a listener
                // needs: it's the path on the Samba share.
                'sizeBytes' => $song->size,
                'modifiedAt' => $song->modified_at?->toIso8601String(),
                'addedAt' => $song->created_at?->toIso8601String(),
                'path' => $song->path,
            ],
        ]);
    }

    /**
     * How many tracks share this song's disc, and how many discs its album has —
     * the denominators behind "2/8" and "1/2". Null when the song is filed under
     * no collection: with no container there is nothing to count against, and a
     * "2/1" would be worse than a blank.
     *
     * Two aggregates on the `(collection_id, disc, track)` index rather than
     * legacy's `$song->album->songs->filter(…)->count()`, which hydrated every
     * sibling row just to count them.
     *
     * @return array{tracks: int|null, discs: int|null}
     */
    private function collectionTotals(Track $song): array
    {
        if ($song->collection_id === null) {
            return ['tracks' => null, 'discs' => null];
        }

        $siblings = fn (): Builder => Track::query()->where('collection_id', $song->collection_id);

        return [
            // Same-disc siblings only. An untagged disc groups with the other
            // untagged ones (`disc IS NULL`), since `= NULL` matches nothing and
            // would report a total of 0 for a whole album's worth of files.
            'tracks' => $siblings()
                ->when(
                    $song->disc === null,
                    fn (Builder $q) => $q->whereNull('disc'),
                    fn (Builder $q) => $q->where('disc', $song->disc)
                )
                ->count(),
            // COUNT(DISTINCT disc) skips NULLs, so an album whose files carry no
            // disc tag counts 0 — which the page reads as "not a multi-disc set"
            // and hides the row, exactly the wanted behaviour.
            'discs' => $siblings()->distinct()->count('disc'),
        ];
    }
}
