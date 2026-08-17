<?php

namespace Tests\Feature\History;

use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * THE LISTENING HISTORY — `GET /history`, the one page that shows the `plays` rows themselves
 * rather than counting them.
 *
 * What is pinned here is everything the server decides, which is nearly all of it:
 *
 *   - IT PAGES OVER DAYS, NOT PLAYS. The unit of the page — of `LIMIT`, of `totalDays`, of the
 *     pager the client draws — is a day that had listening in it. A page holding twenty-five
 *     PLAYS instead would look identical on a small fixture and be wrong the first time
 *     somebody had a long evening.
 *   - IT IS THE READER'S OWN. Two accounts on one instance share the library and nothing else;
 *     a history that leaked would be a list of what somebody else listens to at night.
 *   - THE ORDER IS NEWEST FIRST, twice over — days down the page, and plays within a day.
 *   - A ROW CARRIES THE CREDIT ITS KIND IS KNOWN BY. A song's is its artist; a chapter's is its
 *     AUTHOR, which hangs off the chapter rather than the book (docs/audiobooks.md) and is the
 *     one field this page's shape genuinely disagrees with a queue entry's about.
 *
 * The `hasPlays` shared prop is asserted here too, because it is the same question ("has this
 * reader listened to anything?") asked from a different place: it gates the user menu's way in,
 * and a menu that disagrees with the page behind it is the bug worth catching.
 */
class HistoryPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One music track with a known artist and album, so a row can be identified by name.
     */
    private function song(string $name = 'Paranoid Android'): Track
    {
        return Track::factory()->create([
            'name' => $name,
            'artist_id' => Artist::factory()->create(['name' => 'Radiohead'])->id,
            'collection_id' => Collection::factory()->create(['name' => 'OK Computer'])->id,
        ]);
    }

    /** A listen by `$user` of `$track`, at a given instant. */
    private function played(User $user, Track $track, string $at): Play
    {
        return Play::factory()->create([
            'user_id' => $user->id,
            'track_id' => $track->id,
            'played_at' => Carbon::parse($at),
        ]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/history')->assertRedirect('/login');
    }

    public function test_it_groups_a_readers_listening_into_days_newest_first(): void
    {
        $user = User::factory()->create();
        $song = $this->song();

        $this->played($user, $song, '2026-08-14 09:00:00');
        $this->played($user, $song, '2026-08-16 21:30:00');
        $this->played($user, $song, '2026-08-16 08:15:00');

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('History/HistoryPage')
                // TWO days from three plays: the unit of the page is the day.
                ->has('days', 2)
                ->where('days.0.date', '2026-08-16')
                ->where('days.0.count', 2)
                ->has('days.0.plays', 2)
                ->where('days.1.date', '2026-08-14')
                ->where('days.1.count', 1)
                // Newest first WITHIN the day as well as between days — the evening's last
                // listen is the one a reader is looking for.
                ->where('days.0.plays.0.playedAt', fn (string $iso) => str_contains($iso, 'T21:30'))
                ->where('days.0.plays.1.playedAt', fn (string $iso) => str_contains($iso, 'T08:15'))
                ->where('totalDays', 2)
                ->where('page', 1)
                ->where('perPage', 25)
            );
    }

    public function test_it_shows_only_the_readers_own_listening(): void
    {
        // The whole reason this page is behind auth and scoped: two accounts on one instance
        // share the library and nothing else.
        $user = User::factory()->create();
        $other = User::factory()->create();
        $song = $this->song();

        $this->played($user, $song, '2026-08-16 10:00:00');
        $this->played($other, $song, '2026-08-15 10:00:00');
        $this->played($other, $song, '2026-08-16 11:00:00');

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 1)
                ->where('days.0.count', 1)
                ->where('totalDays', 1)
            );
    }

    public function test_a_song_row_carries_its_artist_album_and_its_own_page(): void
    {
        $user = User::factory()->create();
        $song = $this->song();
        $this->played($user, $song, '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.plays.0.kind', 'music')
                ->where('days.0.plays.0.name', 'Paranoid Android')
                ->where('days.0.plays.0.creator', 'Radiohead')
                ->where('days.0.plays.0.container', 'OK Computer')
                ->where('days.0.plays.0.href', "/music/songs/{$song->id}")
            );
    }

    public function test_a_chapter_row_carries_its_author_and_points_at_the_book(): void
    {
        /*
         * THE FIELD THIS SHAPE EXISTS FOR. A chapter's credit is its AUTHOR — which hangs off
         * the chapter, beside the narrator, so an anthology can name a different one per story
         * — where a queue entry would send `artists.name` and find null. And a chapter has no
         * page of its own, so the row leads to its book.
         */
        $user = User::factory()->create();
        $book = Collection::factory()->audiobook()->create(['name' => 'Necrophobia 1']);
        $chapter = Track::factory()->audiobook()->create([
            'name' => 'Die Ratten im Gemäuer',
            'collection_id' => $book->id,
            'author_id' => Author::factory()->create(['name' => 'H.P. Lovecraft'])->id,
        ]);

        $this->played($user, $chapter, '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.plays.0.kind', 'audiobook')
                ->where('days.0.plays.0.creator', 'H.P. Lovecraft')
                ->where('days.0.plays.0.container', 'Necrophobia 1')
                ->where('days.0.plays.0.href', "/audiobooks/{$book->id}")
            );
    }

    public function test_it_pages_twenty_five_days_at_a_time(): void
    {
        $user = User::factory()->create();
        $song = $this->song();

        // Thirty consecutive days with one listen each: the second page holds the remaining
        // five, and the oldest day is the last row on it.
        foreach (range(0, 29) as $offset) {
            $this->played($user, $song, Carbon::parse('2026-08-30')->subDays($offset)->format('Y-m-d').' 12:00:00');
        }

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 25)
                ->where('days.0.date', '2026-08-30')
                ->where('totalDays', 30)
                ->where('page', 1)
            );

        $this->actingAs($user)
            ->get('/history?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 5)
                ->where('days.0.date', '2026-08-05')
                ->where('days.4.date', '2026-08-01')
                ->where('totalDays', 30)
                ->where('page', 2)
            );
    }

    public function test_a_later_page_carries_only_its_own_days_plays(): void
    {
        /*
         * THE WINDOW IS A RANGE, not a set of dates, so this is the assertion that says the
         * range covers exactly the page it belongs to. Get it wrong in either direction and it
         * is invisible on page one: too wide and every page ships the whole history, too narrow
         * and a day arrives with an empty panel.
         */
        $user = User::factory()->create();
        $song = $this->song();

        foreach (range(0, 26) as $offset) {
            $this->played($user, $song, Carbon::parse('2026-08-30')->subDays($offset)->format('Y-m-d').' 12:00:00');
        }

        $this->actingAs($user)
            ->get('/history?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 2)
                ->has('days.0.plays', 1)
                ->where('days.0.plays.0.playedAt', fn (string $iso) => str_contains($iso, '2026-08-05'))
                ->has('days.1.plays', 1)
                ->where('days.1.plays.0.playedAt', fn (string $iso) => str_contains($iso, '2026-08-04'))
            );
    }

    public function test_a_reader_with_no_listening_gets_an_empty_page_rather_than_an_error(): void
    {
        // Not reachable from the menu — the `plays` prop hides the way in — but it is a real
        // state, and typing the URL must not 500 on a paginator with nothing to page.
        $this->actingAs(User::factory()->create())
            ->get('/history')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 0)
                ->where('totalDays', 0)
            );
    }

    public function test_a_hand_typed_page_number_is_refused_rather_than_guessed_at(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/history?page=nonsense')
            ->assertSessionHasErrors('page');

        $this->actingAs(User::factory()->create())
            ->get('/history?page=0')
            ->assertSessionHasErrors('page');
    }

    public function test_an_empty_page_parameter_is_the_first_page_rather_than_an_error(): void
    {
        /*
         * `ConvertEmptyStringsToNull` is a global middleware, so `?page=` arrives with the key
         * present and the value NULL — which `sometimes` lets through and `integer` then
         * refuses, answering 422 for a URL that was plainly asking for the first page. The same
         * trap CLAUDE.md records for `GET /search?kinds=`; `nullable` is what closes it.
         */
        $user = User::factory()->create();
        $this->played($user, $this->song(), '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get('/history?page=')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('days', 1)->where('page', 1));
    }

    public function test_a_days_header_count_matches_the_rows_it_opens_onto(): void
    {
        // Two answers to one question is one answer too many: the count is taken off the shaped
        // rows rather than from a `count(*)`, so a section cannot announce more than it holds.
        $user = User::factory()->create();
        $song = $this->song();

        $this->played($user, $song, '2026-08-16 21:30:00');
        $this->played($user, $song, '2026-08-16 08:15:00');

        $this->actingAs($user)
            ->get('/history')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.count', 2)
                ->has('days.0.plays', 2)
            );
    }

    public function test_the_shared_prop_says_whether_this_reader_has_listened_to_anything(): void
    {
        // What the user menu draws its entry off. Asserted from an unrelated page, because it
        // rides on every response rather than on this one.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPlays', false));

        $this->played($user, $this->song(), '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPlays', true));
    }

    public function test_the_shared_prop_survives_a_page_that_sends_play_counts_of_its_own(): void
    {
        /*
         * THE SHADOWING TRAP, and the reason this boolean is called `hasPlays` rather than
         * `plays`. Inertia merges a page's own props OVER the shared ones, and `plays` is
         * already what six detail pages call their `{own, others}` PlayCounts pair — so a
         * shared key of that name is replaced on exactly the pages a listener spends most of
         * their time on. The symptom is the nastiest kind: a menu entry that disappears on
         * every song, album, artist, genre, playlist and audiobook page and comes back
         * everywhere else, with nothing failing anywhere.
         *
         * Asserted from a SONG page for that reason, where the two keys would collide.
         */
        $user = User::factory()->create();
        $song = $this->song();
        $this->played($user, $song, '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('hasPlays', true)
                // …and the page's own counts are still there under their own name.
                ->has('plays.own')
            );
    }

    public function test_another_readers_listening_does_not_light_up_the_menu(): void
    {
        // The prop is per-reader like the page: somebody else's listening must not put an entry
        // in this account's menu leading to a page that will be empty.
        $user = User::factory()->create();
        $this->played(User::factory()->create(), $this->song(), '2026-08-16 10:00:00');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPlays', false));
    }
}
