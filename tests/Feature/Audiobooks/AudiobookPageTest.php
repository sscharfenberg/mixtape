<?php

namespace Tests\Feature\Audiobooks;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Narrator;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One book's page (`/audiobooks/{audiobook}`, behind auth) — the hero's facts and the
 * chapters table.
 *
 * THE ANTHOLOGY IS THE CASE WORTH TESTING, and most of what is here is about it: a book whose
 * chapters name several authors and several narrators is what the whole area was reshaped
 * around (M1), and every one of these assertions would have been impossible against a
 * book-level author column. The ordinary single-author book is the easy half and falls out of
 * the same code.
 */
class AudiobookPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A three-chapter anthology: two authors, two narrators, and one chapter crediting
     * nobody — the shape of "Necrophobia 1" in miniature.
     *
     * @return array{0: Collection, 1: array<int, Track>}
     */
    private function anthology(): array
    {
        $book = Collection::factory()->audiobook()->create(['name' => 'Necrophobia 1', 'year' => 2008]);

        $lovecraft = Author::factory()->create(['name' => 'H.P. Lovecraft']);
        $lumley = Author::factory()->create(['name' => 'Brian Lumley']);
        $riedel = Narrator::factory()->create(['name' => 'Lutz Riedel']);
        $nathan = Narrator::factory()->create(['name' => 'David Nathan']);

        $chapters = [
            Track::factory()->audiobook()->create([
                'collection_id' => $book->id, 'author_id' => $lovecraft->id, 'narrator_id' => $riedel->id,
                'name' => 'Die Ratten im Gemäuer', 'disc' => 1, 'track' => 1, 'duration' => 600.0,
            ]),
            Track::factory()->audiobook()->create([
                'collection_id' => $book->id, 'author_id' => $lumley->id, 'narrator_id' => $nathan->id,
                'name' => 'Der Erforscher', 'disc' => 1, 'track' => 2, 'duration' => 300.0,
            ]),
            // The afterword nobody is credited with.
            Track::factory()->audiobook()->create([
                'collection_id' => $book->id, 'author_id' => null, 'narrator_id' => null,
                'name' => 'Nachwort', 'disc' => 2, 'track' => 1, 'duration' => 100.0,
            ]),
        ];

        return [$book, $chapters];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $book = Collection::factory()->audiobook()->create();

        $this->get("/audiobooks/{$book->id}")->assertRedirect('/login');
    }

    public function test_an_album_is_not_a_book(): void
    {
        // `collections` is a unified table, so without the type guard an album's id would
        // render through a page built for books.
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$album->id}")
            ->assertNotFound();
    }

    public function test_the_hero_carries_every_author_and_narrator_the_chapters_name(): void
    {
        [$book] = $this->anthology();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audiobooks/Audiobook/AudiobookPage')
                ->where('audiobook.name', 'Necrophobia 1')
                // Sorted, and DISTINCT — an author with two chapters is named once.
                ->where('audiobook.authors', ['Brian Lumley', 'H.P. Lovecraft'])
                ->where('audiobook.narrators', ['David Nathan', 'Lutz Riedel'])
                ->where('audiobook.year', 2008)
                ->where('audiobook.chapters', 3)
                ->where('audiobook.discs', 2)
                // Raw seconds, not a clock: the page formats against the reader's locale.
                // Compared numerically: a whole number crosses JSON as an int here and as a
                // float on Postgres, and the assertion is about the seconds, not the type.
                ->where('audiobook.duration', fn (float|int $seconds) => (float) $seconds === 1000.0)
                ->where('audiobook.downloadUrl', "/audiobooks/{$book->id}/download")
            );
    }

    public function test_an_author_who_wrote_two_chapters_is_named_once(): void
    {
        // The `distinct()` on the relation, which is the difference between "six authors" and
        // "thirty-three authors" on the real anthology.
        $book = Collection::factory()->audiobook()->create();
        $author = Author::factory()->create(['name' => 'H.P. Lovecraft']);
        Track::factory()->audiobook()->count(2)->sequence(
            ['track' => 1], ['track' => 2],
        )->create(['collection_id' => $book->id, 'author_id' => $author->id]);

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page->where('audiobook.authors', ['H.P. Lovecraft']));
    }

    public function test_the_chapters_open_in_reading_order_with_their_credits(): void
    {
        [$book, $chapters] = $this->anthology();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 3)
                // Disc, then track — the book's reading order, and the only sensible thing to
                // open on. Nothing about the default sort should ever be alphabetical.
                ->where('table.rows.0.name', 'Die Ratten im Gemäuer')
                ->where('table.rows.1.name', 'Der Erforscher')
                ->where('table.rows.2.name', 'Nachwort')
                ->where('table.rows.0.author', 'H.P. Lovecraft')
                ->where('table.rows.0.narrator', 'Lutz Riedel')
                ->where('table.rows.1.author', 'Brian Lumley')
                // The uncredited chapter is null, not an empty string or a borrowed name.
                ->where('table.rows.2.author', null)
                ->where('table.rows.2.narrator', null)
                // No per-row stream URL: pressing a row queues the whole book from the
                // `queueTracks` payload, so a second address for the same bytes would only be
                // something to keep in step.
                ->missing('table.rows.0.streamUrl')
                // The denominators behind "1/2" and "1/2": two discs, two chapters on disc 1.
                ->where('table.rows.0.discTotal', 2)
                ->where('table.rows.0.trackTotal', 2)
            );
    }

    public function test_the_chapter_search_matches_a_credit_as_well_as_a_title(): void
    {
        // Which is what the search is for on a 673-chapter book: "the one Lovecraft wrote".
        [$book] = $this->anthology();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}?search=lumley")
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Der Erforscher')
            );
    }

    public function test_the_queue_payload_is_optional_and_holds_the_whole_book(): void
    {
        /*
         * Not sent with the page — on a 673-chapter book that is a payload nobody browsing
         * asked for — and, when asked for by name, holding CHAPTERS rather than nothing.
         * `QueuePayload::fromQuery` defaults to music-only, so the `only: null` in the
         * controller is the line this asserts.
         */
        [$book] = $this->anthology();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('queueTracks'));

        $url = "/audiobooks/{$book->id}";

        $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                // Without the VERSION the visit answers 409 with a Location header rather than
                // JSON, which in a test looks exactly like the prop being missing.
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create($url)),
                'X-Inertia-Partial-Component' => 'Audiobooks/Audiobook/AudiobookPage',
                'X-Inertia-Partial-Data' => 'queueTracks',
            ])
            ->get($url)
            ->assertJsonCount(3, 'props.queueTracks')
            // Addressed as chapters, which is the whole of what made a book playable.
            ->assertJsonPath('props.queueTracks.0.href', "/audiobooks/{$book->id}")
            // And MARKED as chapters, which is what lets the player offer to stop at the end
            // of one. Carried on the row rather than sniffed out of the stream URL, which
            // would work today and break the moment a row plays from a share link.
            ->assertJsonPath('props.queueTracks.0.isChapter', true);
    }

    public function test_a_book_with_no_art_sends_a_null_cover_rather_than_a_url_that_404s(): void
    {
        $book = Collection::factory()->audiobook()->create(['cover_path' => null]);
        Track::factory()->audiobook()->create(['collection_id' => $book->id, 'cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page->where('audiobook.coverUrl', null));
    }
}
