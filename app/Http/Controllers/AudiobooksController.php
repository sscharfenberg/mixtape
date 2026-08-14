<?php

namespace App\Http\Controllers;

use App\Enums\CollectionType;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Narrator;
use App\Models\Track;
use App\Services\Library\LibraryStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Audiobooks area (`GET /audiobooks`, route `audiobooks`, behind auth) — the collection's
 * stats over three ways into it: the BOOKS themselves, and the same books grouped by who
 * wrote them and by who reads them.
 *
 * WHY IT IS NOT A DATATABLE, unlike every Music listing (the owner's call): this app is a
 * music player that also holds audiobooks, and twenty books do not need sorting, paging or a
 * column of file sizes. They need to be recognisable, which means covers. So the books are
 * the shared `Discography` grid — the component the artist and genre pages already use for
 * exactly this — and the two credit tabs are accordions over the same grid.
 *
 * EVERY PANEL SHIPS ON EVERY REQUEST, like the tabbed Music pages: switching tabs costs no
 * round trip and raises no spinner over content already on screen. That is affordable here in
 * a way it would not be for a library of thousands, because the whole area is twenty books —
 * and the grids are counts and names, not chapters.
 *
 * AN ANTHOLOGY APPEARS UNDER EVERY CONTRIBUTOR, in both credit tabs, which is the point of
 * the tabs rather than an accident: "Necrophobia 1" is filed under all six of its authors and
 * all four of its narrators, because a reader looking for Lovecraft wants to find it there.
 */
class AudiobooksController extends Controller
{
    /** Render the Audiobooks browse page. */
    public function __invoke(): Response
    {
        return Inertia::render('Audiobooks/AudiobooksPage', [
            // Closures, so the widget's refresh button can re-run one of these without the
            // page around it — the arrangement MusicController's stats and widgets use.
            'stats' => fn (): array => LibraryStats::audiobooks(),
            'books' => fn (): array => $this->books(),
            'authors' => fn (): array => $this->credits(Author::class, 'author_id'),
            'narrators' => fn (): array => $this->credits(Narrator::class, 'narrator_id'),
        ]);
    }

    /**
     * Every book as a Discography tile — cover, title, year, chapter count, playing time.
     *
     * Newest first, with undated books at the END in both directions: the NULL flag stays
     * ascending while the year reverses, spelled as a CASE rather than left to the engine
     * because Postgres puts NULLs first under DESC and SQLite does not. Then name, so the
     * order is total. The artist page's discography carries the same three lines and the
     * fuller reasoning.
     *
     * @return array<int, array<string, mixed>>
     */
    private function books(?Builder $scope = null): array
    {
        return ($scope ?? Collection::query())
            ->where('collections.type', CollectionType::Audiobook)
            ->withCount('tracks')
            ->withSum('tracks', 'duration')
            ->addSelect([
                // Whether ANY chapter carries embedded art — the cover route's fallback when
                // the directory has no image. Selected here so the grid costs no filesystem
                // access at all.
                'embedded_cover_id' => Track::query()
                    ->select('id')
                    ->whereColumn('tracks.collection_id', 'collections.id')
                    ->where('tracks.cover', true)
                    ->limit(1)
                    ->toBase(),
            ])
            ->orderByRaw('case when collections.year is null then 1 else 0 end')
            ->orderByDesc('collections.year')
            ->orderBy('collections.name')
            ->get()
            ->map(fn (Collection $book): array => [
                'id' => $book->id,
                'name' => $book->name,
                'year' => $book->year,
                // The Discography tile calls this `songs`; here it counts chapters. Reusing
                // the component means reusing its vocabulary, and inventing a parallel field
                // would mean forking the component to read it.
                'songs' => (int) $book->tracks_count,
                'duration' => $book->tracks_sum_duration === null ? null : (float) $book->tracks_sum_duration,
                'coverUrl' => $book->cover_path !== null || $book->embedded_cover_id !== null
                    ? route('audiobooks.cover', $book->id, absolute: false)
                    : null,
                'href' => route('audiobooks.show', $book->id, absolute: false),
            ])
            ->all();
    }

    /**
     * One credit tab: every author (or narrator) with the books they contributed to and how
     * long those contributions run.
     *
     * ONE QUERY PER CREDIT ROW rather than one per book, and the shape is deliberate: the
     * accordion header needs a count and a total, and the panel needs the same tiles the
     * Books tab draws. So the books are fetched once per person through the same {@see books}
     * builder — which keeps the tile shape in one place and means a change to what a tile
     * shows reaches all three tabs.
     *
     * THE PLAYTIME IS THEIR OWN, not the books'. An author credited with three stories in an
     * anthology is worth three stories of listening, not the whole book — so it sums the
     * chapters carrying THEIR id rather than the durations of the books they appear in. The
     * book COUNT is the opposite question ("where can I find them"), so an anthology counts
     * once however many chapters they wrote in it.
     *
     * @param  class-string<Author|Narrator>  $model
     * @param  string  $foreignKey  the column on `tracks` that names this credit
     * @return array<int, array<string, mixed>>
     */
    private function credits(string $model, string $foreignKey): array
    {
        return $model::query()
            ->has('tracks')
            ->withSum(['tracks' => fn (Builder $query) => $query], 'duration')
            ->orderBy('name')
            ->get()
            ->map(function (Author|Narrator $credit) use ($foreignKey): array {
                $books = $this->books(
                    Collection::query()->whereIn(
                        'collections.id',
                        DB::table('tracks')
                            ->select('collection_id')
                            ->where($foreignKey, $credit->id)
                            ->whereNotNull('collection_id')
                    )
                );

                return [
                    'id' => $credit->id,
                    'name' => $credit->name,
                    'books' => $books,
                    'bookCount' => count($books),
                    // Their own chapters' playing time — see the docblock.
                    'duration' => $credit->tracks_sum_duration === null
                        ? null
                        : (float) $credit->tracks_sum_duration,
                ];
            })
            ->all();
    }
}
