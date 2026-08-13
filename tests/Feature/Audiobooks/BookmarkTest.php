<?php

namespace Tests\Feature\Audiobooks;

use App\Models\AudiobookBookmark;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-book resume: `PUT /audiobooks/{audiobook}/bookmark` going up, the `bookmark` prop and
 * the opening PAGE coming back down.
 *
 * THE FEATURE THE AREA EXISTS FOR, in the owner's words: knowing you are at chapter 279 and
 * not skipping through half a book to find it. Three properties carry that, and each is a
 * different way of losing your place:
 *
 * - **One row per (reader, book)**, so three books can be in flight and none of them forgets
 *   because you spent an evening on another. The composite primary key is what enforces it.
 * - **A chapter of ANOTHER book is refused**, or a bookmark could name any chapter in the
 *   library and the resume would land somewhere else entirely.
 * - **The table opens on the bookmarked chapter's page**, which on a 673-chapter book is the
 *   difference between finding your place and paging for it.
 */
class BookmarkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A book of `$chapters` chapters, numbered from 1, in reading order.
     *
     * @return array{0: Collection, 1: array<int, Track>}
     */
    private function book(int $chapters = 3): array
    {
        $book = Collection::factory()->audiobook()->create();
        $rows = [];

        foreach (range(1, $chapters) as $number) {
            $rows[] = Track::factory()->audiobook()->create([
                'collection_id' => $book->id,
                'disc' => 1,
                'track' => $number,
                'name' => sprintf('Kapitel %03d', $number),
            ]);
        }

        return [$book, $rows];
    }

    public function test_a_guest_cannot_bookmark(): void
    {
        [$book, $chapters] = $this->book();

        $this->putJson("/audiobooks/{$book->id}/bookmark", [
            'trackId' => $chapters[0]->id,
            'positionMs' => 1_000,
        ])->assertUnauthorized();
    }

    public function test_it_stores_where_the_reader_got_to(): void
    {
        [$book, $chapters] = $this->book();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson("/audiobooks/{$book->id}/bookmark", [
                'trackId' => $chapters[1]->id,
                'positionMs' => 872_000,
            ])
            ->assertNoContent();

        $bookmark = AudiobookBookmark::query()->where('user_id', $user->id)->sole();
        $this->assertSame($book->id, $bookmark->collection_id);
        $this->assertSame($chapters[1]->id, $bookmark->track_id);
        $this->assertSame(872_000, $bookmark->position_ms);
    }

    public function test_a_second_write_moves_the_bookmark_rather_than_adding_a_row(): void
    {
        // The composite key doing its job: two rows for one book could only ever disagree
        // about where the reader is.
        [$book, $chapters] = $this->book();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[0]->id, 'positionMs' => 10]);
        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[2]->id, 'positionMs' => 20]);

        $this->assertSame(1, AudiobookBookmark::query()->count());
        $this->assertSame($chapters[2]->id, AudiobookBookmark::query()->sole()->track_id);
    }

    public function test_several_books_are_in_flight_at_once(): void
    {
        // The whole reason this is not `player_states`: putting one book down for an evening
        // must not cost you your place in it.
        [$first, $firstChapters] = $this->book();
        [$second, $secondChapters] = $this->book();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$first->id}/bookmark", ['trackId' => $firstChapters[2]->id, 'positionMs' => 30]);
        $this->actingAs($user)->putJson("/audiobooks/{$second->id}/bookmark", ['trackId' => $secondChapters[0]->id, 'positionMs' => 40]);

        $this->assertSame(2, AudiobookBookmark::query()->count());
        $this->assertSame(
            $firstChapters[2]->id,
            AudiobookBookmark::query()->where('collection_id', $first->id)->sole()->track_id
        );
    }

    public function test_two_readers_keep_their_own_places_in_one_book(): void
    {
        [$book, $chapters] = $this->book();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[0]->id, 'positionMs' => 1]);
        $this->actingAs($bob)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[2]->id, 'positionMs' => 2]);

        $this->assertSame(2, AudiobookBookmark::query()->count());
        $this->assertSame($chapters[0]->id, AudiobookBookmark::query()->where('user_id', $alice->id)->sole()->track_id);
    }

    public function test_a_chapter_of_another_book_is_refused(): void
    {
        // Otherwise a bookmark could name any chapter in the library, and the resume would
        // open a different book.
        [$book] = $this->book();
        [, $elsewhere] = $this->book();

        $this->actingAs(User::factory()->create())
            ->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $elsewhere[0]->id, 'positionMs' => 0])
            ->assertJsonValidationErrors('trackId');
    }

    public function test_a_song_is_refused_as_a_chapter(): void
    {
        [$book] = $this->book();
        $song = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $song->id, 'positionMs' => 0])
            ->assertJsonValidationErrors('trackId');
    }

    public function test_an_album_cannot_be_bookmarked(): void
    {
        $album = Collection::factory()->create();
        $song = Track::factory()->create(['collection_id' => $album->id]);

        $this->actingAs(User::factory()->create())
            ->putJson("/audiobooks/{$album->id}/bookmark", ['trackId' => $song->id, 'positionMs' => 0])
            ->assertNotFound();
    }

    public function test_a_negative_position_is_refused(): void
    {
        [$book, $chapters] = $this->book();

        $this->actingAs(User::factory()->create())
            ->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[0]->id, 'positionMs' => -1])
            ->assertJsonValidationErrors('positionMs');
    }

    public function test_the_page_carries_the_bookmark_back(): void
    {
        [$book, $chapters] = $this->book();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", [
            'trackId' => $chapters[1]->id,
            'positionMs' => 872_000,
        ]);

        $this->actingAs($user)
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookmark.trackId', $chapters[1]->id)
                ->where('bookmark.positionMs', 872_000)
            );
    }

    public function test_a_book_never_started_has_no_bookmark(): void
    {
        [$book] = $this->book();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page->where('bookmark', null));
    }

    public function test_one_readers_bookmark_is_invisible_to_another(): void
    {
        [$book, $chapters] = $this->book();
        $alice = User::factory()->create();

        $this->actingAs($alice)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[0]->id, 'positionMs' => 5]);

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page->where('bookmark', null));
    }

    public function test_the_table_opens_on_the_bookmarked_chapters_page(): void
    {
        /*
         * THE OWNER'S QUESTION, and the answer is yes. 120 chapters at 50 to a page: chapter
         * 63 is on page 2, and nothing about paging would ever take a reader there.
         */
        [$book, $chapters] = $this->book(120);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", [
            'trackId' => $chapters[62]->id,   // chapter 63, zero-indexed
            'positionMs' => 0,
        ]);

        $this->actingAs($user)
            ->get("/audiobooks/{$book->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.page', 2)
                ->where('table.rows.12.name', 'Kapitel 063')
            );
    }

    public function test_asking_for_a_page_explicitly_still_means_that_page(): void
    {
        // Or the pager's first button would bounce back to the bookmark and the table would
        // be unusable — the reason `defaultPage` applies only when the request names none.
        [$book, $chapters] = $this->book(120);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[62]->id, 'positionMs' => 0]);

        $this->actingAs($user)
            ->get("/audiobooks/{$book->id}?page=1")
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.page', 1)
                ->where('table.rows.0.name', 'Kapitel 001')
            );
    }

    public function test_the_opening_page_follows_the_readers_page_size(): void
    {
        // The arithmetic reads the size the response will actually use, so 25 rows a page
        // puts chapter 63 on page 3 rather than leaving the two to disagree.
        [$book, $chapters] = $this->book(120);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[62]->id, 'positionMs' => 0]);

        $this->actingAs($user)
            ->get("/audiobooks/{$book->id}?pageSize=25")
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.page', 3)
                ->where('table.rows.12.name', 'Kapitel 063')
            );
    }

    public function test_a_bookmark_dies_with_its_chapter(): void
    {
        // A chapter can vanish between scans. A resume that lands on the wrong story is worse
        // than one that starts the book again, so the FK cascades.
        [$book, $chapters] = $this->book();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson("/audiobooks/{$book->id}/bookmark", ['trackId' => $chapters[0]->id, 'positionMs' => 9]);
        $chapters[0]->delete();

        $this->assertSame(0, AudiobookBookmark::query()->count());
    }
}
