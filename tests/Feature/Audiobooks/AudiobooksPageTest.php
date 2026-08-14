<?php

namespace Tests\Feature\Audiobooks;

use App\Models\Author;
use App\Models\Collection;
use App\Models\Narrator;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Audiobooks entry page (`/audiobooks`, behind auth) — the stats card and the three tabs.
 *
 * WHAT IS WORTH ASSERTING is the grouping, because it is the whole point of the credit tabs
 * and the one thing that could not exist before the author moved onto the chapter: an
 * anthology has to appear under EVERY contributor, an author's book count has to be books
 * rather than chapters, and their playing time has to be their own chapters rather than the
 * length of the books they appear on.
 */
class AudiobooksPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two books sharing an author: a solo novel, and an anthology that author wrote two
     * chapters of. Miniature "H.P. Lovecraft appears in Necrophobia 1 and 2".
     *
     * @return array{0: Collection, 1: Collection, 2: Author}
     */
    private function library(): array
    {
        $lovecraft = Author::factory()->create(['name' => 'H.P. Lovecraft']);
        $lumley = Author::factory()->create(['name' => 'Brian Lumley']);
        $riedel = Narrator::factory()->create(['name' => 'Lutz Riedel']);

        $novel = Collection::factory()->audiobook()->create(['name' => 'Berge des Wahnsinns', 'year' => 2010]);
        Track::factory()->audiobook()->create([
            'collection_id' => $novel->id, 'author_id' => $lovecraft->id, 'narrator_id' => $riedel->id,
            'track' => 1, 'duration' => 100.0, 'size' => 1_000,
        ]);

        $anthology = Collection::factory()->audiobook()->create(['name' => 'Necrophobia 1', 'year' => 2008]);
        Track::factory()->audiobook()->create([
            'collection_id' => $anthology->id, 'author_id' => $lovecraft->id, 'narrator_id' => $riedel->id,
            'track' => 1, 'duration' => 200.0, 'size' => 2_000,
        ]);
        Track::factory()->audiobook()->create([
            'collection_id' => $anthology->id, 'author_id' => $lovecraft->id, 'narrator_id' => $riedel->id,
            'track' => 2, 'duration' => 300.0, 'size' => 3_000,
        ]);
        Track::factory()->audiobook()->create([
            'collection_id' => $anthology->id, 'author_id' => $lumley->id, 'narrator_id' => $riedel->id,
            'track' => 3, 'duration' => 400.0, 'size' => 4_000,
        ]);

        return [$novel, $anthology, $lovecraft];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/audiobooks')->assertRedirect('/login');
    }

    public function test_the_stats_card_counts_books_chapters_and_both_credits(): void
    {
        $this->library();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audiobooks/AudiobooksPage')
                ->where('stats.books', 2)
                ->where('stats.chapters', 4)
                ->where('stats.authors', 2)
                ->where('stats.narrators', 1)
                ->where('stats.sizeBytes', 10_000)
                ->where('stats.playtimeSeconds', fn (float|int $s) => (float) $s === 1000.0)
                // The years the BOOKS span, drawn as one range on the card (2026-08-14). Two
                // nullable numbers rather than a string, because a year must not be
                // locale-separated like the counts beside it.
                ->where('stats.firstYear', 2008)
                ->where('stats.lastYear', 2010)
            );
    }

    public function test_music_is_not_counted_among_the_audiobooks(): void
    {
        // Both tables are unified, so every number on this card is a `type` filter away from
        // being the music library's.
        $this->library();
        Track::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.books', 2)
                ->where('stats.chapters', 4)
                ->has('books', 2)
            );
    }

    public function test_the_books_tab_lists_every_book_newest_first(): void
    {
        $this->library();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->has('books', 2)
                ->where('books.0.name', 'Berge des Wahnsinns')
                ->where('books.1.name', 'Necrophobia 1')
                // The Discography tile's own vocabulary — reusing the component means reusing
                // its field names, and `songs` counts chapters here.
                ->where('books.1.songs', 3)
                ->where('books.0.href', fn (string $href) => str_starts_with($href, '/audiobooks/'))
            );
    }

    public function test_an_anthology_appears_under_every_author_who_wrote_in_it(): void
    {
        /*
         * The point of the credit tabs, and the assertion that was impossible before M1: with
         * a book-level author column, "Necrophobia 1" could belong to exactly one of its six
         * authors — and a reader looking for Lovecraft would not have found it.
         */
        [, $anthology] = $this->library();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->has('authors', 2)
                // Alphabetical: Brian Lumley, then H.P. Lovecraft.
                ->where('authors.0.name', 'Brian Lumley')
                ->where('authors.1.name', 'H.P. Lovecraft')
                // Lumley wrote one chapter of the anthology and nothing else.
                ->where('authors.0.bookCount', 1)
                ->where('authors.0.books.0.name', 'Necrophobia 1')
                // Lovecraft is on both books — and the anthology counts ONCE despite his two
                // chapters in it, which is the `distinct()` on the relation.
                ->where('authors.1.bookCount', 2)
                ->where('authors.1.books.1.id', $anthology->id)
            );
    }

    public function test_a_credit_is_worth_their_own_chapters_not_the_books_they_appear_on(): void
    {
        // Lovecraft: 100s in the novel + 200s + 300s in the anthology = 600s. NOT the 1000s
        // those two books run to — he did not write Lumley's chapter.
        $this->library();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->where('authors.1.name', 'H.P. Lovecraft')
                ->where('authors.1.duration', fn (float|int $s) => (float) $s === 600.0)
                ->where('authors.0.duration', fn (float|int $s) => (float) $s === 400.0)
            );
    }

    public function test_the_narrators_tab_groups_the_same_books_by_who_reads_them(): void
    {
        $this->library();

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->has('narrators', 1)
                ->where('narrators.0.name', 'Lutz Riedel')
                // He reads both books, and all four chapters.
                ->where('narrators.0.bookCount', 2)
                ->where('narrators.0.duration', fn (float|int $s) => (float) $s === 1000.0)
            );
    }

    public function test_a_credit_with_no_chapters_left_is_not_listed(): void
    {
        // The scanner prunes orphans, but a taxonomy row can outlive its chapters between
        // runs — and an author with an empty shelf is a section that opens onto nothing.
        $this->library();
        Author::factory()->create(['name' => 'Nobody At All']);

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page->has('authors', 2));
    }

    public function test_an_empty_library_answers_with_zeroes_rather_than_nulls(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.books', 0)
                ->where('stats.sizeBytes', 0)
                ->where('stats.playtimeSeconds', fn (float|int $s) => (float) $s === 0.0)
                // …except the year range, which is NULL rather than zero, and deliberately so:
                // "0–0" would be a fact the library does not have. The card drops the tile.
                ->where('stats.firstYear', null)
                ->where('stats.lastYear', null)
                ->has('books', 0)
                ->has('authors', 0)
            );
    }

    public function test_the_year_range_ignores_music_albums_entirely(): void
    {
        // `collections` holds albums and audiobooks in one table, so the range is one `type`
        // filter away from being the music library's — and an album either side of the books'
        // span is what makes a missing filter visible rather than merely possible.
        $this->library();
        Collection::factory()->create(['year' => 1900]);
        Collection::factory()->create(['year' => 2050]);

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.firstYear', 2008)
                ->where('stats.lastYear', 2010)
            );
    }

    public function test_an_untagged_book_does_not_drag_the_range_to_null(): void
    {
        // SQL's MIN/MAX skip nulls, which is the behaviour the card relies on: one undated book
        // among dated ones must narrow nothing. Only a library where NOTHING carries a year
        // answers null, and that is the case above.
        $this->library();
        Collection::factory()->audiobook()->create(['name' => 'Undatiert', 'year' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/audiobooks')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.firstYear', 2008)
                ->where('stats.lastYear', 2010)
            );
    }
}
