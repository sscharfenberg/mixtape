<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\DataTableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Songs sub-section (`GET /music/songs`, route `music.songs`, behind
 * auth) — the full song listing as a server-driven DataTable (sort / search /
 * paginate all in the URL). Linked from the SongsWidget footer.
 */
class SongsController extends Controller
{
    /**
     * Render the Songs listing. Left-joins the taxonomy tables so a song's
     * artist / album / genre names are one query (and sortable by those columns),
     * then hands the query to DataTableService which reads the URL state and
     * shapes the TableResponse the frontend DataTable expects.
     */
    public function __invoke(Request $request): Response
    {
        $query = Track::query()
            ->where('tracks.type', TrackType::Music)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->leftJoin('genres', 'tracks.genre_id', '=', 'genres.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.duration',
                'artists.name as artist_name',
                'collections.name as album_name',
                'genres.name as genre_name',
            ]);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'artist', 'album', 'genre', 'duration'],
            // Sort keys → real columns: the frontend sorts by `artist`, the DB
            // column is `artists.name` after the join. (ORDER BY is fine on the
            // name columns' nondeterministic ICU collation — only LIKE isn't.)
            sortColumnMap: [
                'name' => 'tracks.name',
                'artist' => 'artists.name',
                'album' => 'collections.name',
                'genre' => 'genres.name',
                'duration' => 'tracks.duration',
            ],
            defaultSort: 'name',
            searchCallback: function (Builder $q, string $search): void {
                // The taxonomy `name` columns carry the case-insensitive, NON-
                // deterministic ICU collation, and Postgres forbids ILIKE on that
                // ("nondeterministic collations are not supported for ILIKE"). Pin
                // each match to the deterministic "C" collation so ILIKE is legal;
                // it case-folds ASCII, which is enough here. (`tracks.name` is
                // default-collated already, but "C" is harmless.) The proper
                // accent-aware substring search via pg_trgm is deferred.
                $like = '%'.$search.'%';
                $q->where(function (Builder $q) use ($like): void {
                    $q->whereRaw('tracks.name COLLATE "C" ILIKE ?', [$like])
                        ->orWhereRaw('artists.name COLLATE "C" ILIKE ?', [$like])
                        ->orWhereRaw('collections.name COLLATE "C" ILIKE ?', [$like])
                        ->orWhereRaw('genres.name COLLATE "C" ILIKE ?', [$like]);
                });
            },
            rowMapper: fn (Track $song): array => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist_name,
                'album' => $song->album_name,
                'genre' => $song->genre_name,
                'duration' => $song->duration !== null ? self::formatDuration((float) $song->duration) : null,
            ],
        );

        return Inertia::render('Music/Songs/SongsPage', [
            'table' => $table,
        ]);
    }

    /**
     * Format a track length (seconds) as m:ss (or h:mm:ss past an hour) for
     * display — the raw float seconds mean nothing to a listener.
     */
    private static function formatDuration(float $seconds): string
    {
        $total = (int) round($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
