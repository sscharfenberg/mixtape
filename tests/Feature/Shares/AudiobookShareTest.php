<?php

namespace Tests\Feature\Shares;

use App\Models\Author;
use App\Models\Collection;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sharing a whole audiobook — the last of `docs/sharing.md`'s blocked rows, which was only ever
 * waiting on the area having a page to share FROM.
 *
 * IT COST NO MIGRATION, and these tests are mostly about the consequence of that: an audiobook
 * share and an album share are the same row, stored in the same `collection_id`, admitted by
 * the same CHECK. Which means the thing that can go wrong is not the grant — that was already
 * type-blind, and `ShareMediaTest` has streamed an audiobook chapter through a collection share
 * since before this existed — but the app's ability to tell the two APART afterwards:
 *
 * - `ShareGrant::subject()` reads a kind back off whichever FK is set, and `collection_id` is
 *   the one FK that does not name its own kind. It has to ask the row.
 * - The mint rule narrows on `type` in BOTH directions now, or an album id passed as
 *   `subject: "audiobook"` would mint a share the page that offered it never showed.
 * - The guest page says different words about a book than about a record: its credit is its
 *   AUTHORS, plural, and it has no genre at all.
 */
class AudiobookShareTest extends TestCase
{
    use RefreshDatabase;

    /** A two-chapter book by two authors, so the guest page's credit has something to join. */
    private function book(string $name = 'Necrophobia 1'): Collection
    {
        $book = Collection::factory()->audiobook()->create(['name' => $name, 'year' => 2008]);

        Track::factory()->audiobook()->create([
            'collection_id' => $book->id,
            'author_id' => Author::factory()->create(['name' => 'H.P. Lovecraft'])->id,
            'track' => 1,
        ]);
        Track::factory()->audiobook()->create([
            'collection_id' => $book->id,
            'author_id' => Author::factory()->create(['name' => 'Brian Lumley'])->id,
            'track' => 2,
        ]);

        return $book;
    }

    public function test_it_mints_a_link_for_an_audiobook(): void
    {
        $user = User::factory()->create();
        $book = $this->book();

        $response = $this->actingAs($user)
            ->postJson('/shares', ['subject' => 'audiobook', 'id' => $book->id])
            ->assertOk();

        $share = Share::query()->sole();

        // The SAME column an album share uses — no new FK, which is why this needed no
        // migration.
        $this->assertSame($book->id, $share->collection_id);
        $this->assertSame($user->id, $share->user_id);
        $this->assertSame(url('/s/'.$share->id), $response->json('url'));
    }

    public function test_an_album_cannot_be_minted_as_an_audiobook(): void
    {
        // The narrowing that cuts both ways: a share must not claim to be something the page
        // that minted it never showed.
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'audiobook', 'id' => $album->id])
            ->assertJsonValidationErrors('id');
    }

    public function test_an_audiobook_cannot_be_minted_as_an_album(): void
    {
        $book = $this->book();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => $book->id])
            ->assertJsonValidationErrors('id');
    }

    public function test_a_shared_book_reads_as_an_audiobook_and_not_as_an_album(): void
    {
        /*
         * `collection_id` is the one FK that does not name its own kind, so `subject()` has to
         * read the row's `type`. Get this wrong and a shared book calls itself an album to
         * every guest who opens it — in German, with the wrong article.
         */
        $book = $this->book();
        $share = Share::factory()->ofAlbum($book)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Share/SharePage')
                ->where('share.kind', 'audiobook')
                ->where('subject.name', 'Necrophobia 1')
                // Its credit is its AUTHORS, joined — an anthology has several, where an album
                // has one album-artist.
                ->where('subject.artist', 'Brian Lumley, H.P. Lovecraft')
                ->where('subject.year', 2008)
                // No genre: the tracks CHECK forbids an audiobook one, so there is nothing to
                // derive rather than nothing found.
                ->where('subject.genre', null)
            );
    }

    public function test_the_guest_page_points_at_the_books_own_cover(): void
    {
        /*
         * A BOOK IS A `collections` ROW LIKE AN ALBUM, so it needs its own arm in
         * ShareArtwork::hero(). Matching only Song and Album lets every shared audiobook fall
         * to `default => false`, and the hero draws CoverImage's placeholder glyph beside a
         * book that has a perfectly good Folder.jpg. The social card has the same hole, through
         * the same method.
         *
         * `cover_path` rather than a file on disk: what is asserted here is which URL the page
         * is given, and `existsForAlbum` answers off the column. The bytes at the other end are
         * ShareMediaTest's half.
         */
        $book = $this->book();
        $book->update(['cover_path' => 'Necrophobia 1/Folder.jpg']);
        $share = Share::factory()->ofAlbum($book)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subject.coverUrl', route('shares.cover', $share, absolute: false))
                // Not a fan: a book has one picture of its own, so the sleeves stay empty.
                ->where('subject.sleeves', [])
            );
    }

    public function test_a_book_with_no_artwork_is_sent_without_a_cover_url(): void
    {
        // The other half of the rule, and the reason the placeholder exists: a null is what
        // lets the hero draw its glyph rather than point an <img> at a route that 404s.
        //
        // BOTH SOURCES SILENCED EXPLICITLY. `existsForAlbum` is `cover_path || any track with
        // art`, and TrackFactory rolls `cover` on `boolean(70)` — so left to the factory this
        // asserts null about nine times in ten and fails the tenth, in a file nobody would
        // think to look at.
        $book = $this->book();
        $book->tracks()->update(['cover' => false]);
        $share = Share::factory()->ofAlbum($book)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subject.coverUrl', null));
    }

    public function test_a_shared_album_still_reads_as_an_album(): void
    {
        // The control for the case above — telling the two apart must not have moved albums.
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->create(['collection_id' => $album->id]);
        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('share.kind', 'album'));
    }

    public function test_a_guest_can_play_every_chapter_of_a_shared_book(): void
    {
        // The grant itself, which was type-blind long before this: a collection share grants
        // that collection's tracks, whatever kind of collection it is.
        $book = $this->book();
        $share = Share::factory()->ofAlbum($book)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tracks', 2));
    }

    public function test_the_owners_list_calls_it_an_audiobook(): void
    {
        $user = User::factory()->create();
        $book = $this->book();
        Share::factory()->ofAlbum($book)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard/shared')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shares.0.kind', 'audiobook')
                ->where('shares.0.name', 'Necrophobia 1')
            );
    }
}
