<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Track;
use App\Services\Media\CoverService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One album's detail page (`GET /music/albums/{album}`, route
 * `music.albums.show`, behind auth) — the row-click target of the Albums listing.
 *
 * A SCAFFOLD for now, and deliberately the same shape the song page had at this
 * stage: it identifies the album (art, title, the handful of facts that describe the
 * whole container) and stops there. The track listing — which is the actual point of
 * an album page — comes next, together with the play/queue controls the hero will
 * grow once there is a player at all (docs/app-rewrite.md).
 *
 * Sends RAW values like every other controller here: seconds for the playing time,
 * an ISO-8601 instant for the file date, counts as counts. Formatting happens on the
 * page against the viewer's locale and timezone (Utils/formatting.ts).
 */
class AlbumController extends Controller
{
    /**
     * Render one album.
     *
     * `{album}` resolves through implicit binding on the collection UUID, so an
     * unknown id is a 404 before this runs; the type check is what keeps an audiobook
     * or a podcast show — same table — from being served as an album, exactly as
     * SongController guards the track table's other types.
     */
    public function __invoke(Collection $album, CoverService $covers): Response
    {
        abort_unless($album->type === CollectionType::Album, 404);

        $album->load('albumArtist:id,name');

        $totals = $this->trackTotals($album);

        return Inertia::render('Music/Albums/Album/AlbumPage', [
            'album' => [
                'id' => $album->id,
                'name' => $album->name,
                'artist' => $album->albumArtist?->name,
                'year' => $album->year,

                'songs' => $totals['songs'],
                // Floored to 1 for the same reason the listing floors it: a rip whose
                // files carry no disc tag counts 0 distinct discs, and it is still one
                // disc. Same COUNT(DISTINCT disc) the song page's "1/2" comes from.
                'discs' => max(1, $totals['discs']),
                'duration' => $totals['duration'],
                'modifiedAt' => $totals['modifiedAt'],

                // The hero's <img> source, or null when the album has art from
                // neither source — decided here (no extraction) so the page renders
                // its placeholder instead of pointing an <img> at a 404.
                'coverUrl' => $covers->existsForAlbum($album)
                    ? route('music.albums.cover', $album->id, absolute: false)
                    : null,
            ],
        ]);
    }

    /**
     * The four numbers that describe the container rather than any one file: how many
     * songs, how many discs, how long it plays, and when its newest file changed.
     *
     * One aggregate query over the `(collection_id, disc, track)` index instead of
     * four, and instead of hydrating every track row to count it — the same reason
     * SongController computes its totals in SQL. `modified_at` stands in for a
     * "modified" date the collections table doesn't have: after a bulk import a
     * file's mtime is the truest thing about when an album last changed.
     *
     * @return array{songs: int, discs: int, duration: float|null, modifiedAt: string|null}
     */
    private function trackTotals(Collection $album): array
    {
        $totals = Track::query()
            ->where('collection_id', $album->id)
            ->selectRaw('count(*) as songs')
            ->selectRaw('count(distinct disc) as discs')
            ->selectRaw('sum(duration) as duration')
            ->selectRaw('max(modified_at) as modified_at')
            ->first();

        return [
            'songs' => (int) $totals?->songs,
            'discs' => (int) $totals?->discs,
            'duration' => $totals?->duration === null ? null : (float) $totals->duration,
            // Parsed explicitly rather than left to a model cast: `max(modified_at)`
            // is a raw aggregate on a select, not the model's own `modified_at`
            // attribute, so no cast applies to it and it arrives as whatever string
            // the driver formats a timestamp as.
            'modifiedAt' => $totals?->modified_at === null
                ? null
                : Carbon::parse($totals->modified_at)->toIso8601String(),
        ];
    }
}
