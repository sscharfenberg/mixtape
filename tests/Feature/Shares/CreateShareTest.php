<?php

namespace Tests\Feature\Shares;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `POST /shares` — minting the link that lets someone WITHOUT an account listen to one
 * subject (docs/sharing.md), pressed by the "share" button in a song / album / artist hero.
 *
 * NEARLY ALL OF THIS FEATURE IS A SERVER DECISION, which is why this file carries most of
 * its tests. What a reader would notice going wrong, and so what is pinned here:
 *
 *   - WHAT MAY BE SHARED AT ALL. A genre and a playlist are refused, and neither is refused
 *     by a check somewhere downstream — `ShareSubject` has no case for either, so the enum
 *     rule is the whole enforcement, and a test is the only thing standing between that and
 *     someone "helpfully" adding a case later.
 *   - THE ID REALLY IS THAT KIND OF THING. `tracks` and `collections` are unified tables
 *     holding audiobook material beside music, so an audiobook chapter's id sent as a song
 *     must not mint a link the song page could never have offered.
 *   - PRESSING TWICE DOES NOT MINT TWICE. The reader gets their own live link back, which is
 *     what keeps "My shares" a list of things shared rather than of presses — and, more to
 *     the point, what stops a re-send quietly extending the seven-day rule.
 *   - SEVEN DAYS, from minting, on every subject.
 *   - ONE SUBJECT PER ROW. The CHECK enforcing it is Postgres-only (sqlite runs the suite),
 *     so what is asserted here is that the app writes exactly one FK — which is the half a
 *     constraint cannot fix in production anyway.
 *
 * Nothing here FOLLOWS the URL it mints. What a guest gets from `/s/{share}` is its own pair of
 * files — ShowShareTest and ShareMediaTest beside this one — plus the one Playwright spec that
 * belongs in `tests/e2e/guest/`, since it is the only journey in the app with no session at all.
 */
class CreateShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $track = Track::factory()->create();

        $this->post('/shares', ['subject' => 'song', 'id' => $track->id])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_it_mints_a_link_for_a_song(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/shares', ['subject' => 'song', 'id' => $track->id])
            ->assertOk();

        $share = Share::query()->sole();

        $this->assertSame($track->id, $share->track_id);
        $this->assertSame($user->id, $share->user_id);
        // The id IS the capability, so the URL has to carry it — a link naming the song
        // instead would be a link to a page behind `auth`.
        $this->assertSame(url('/s/'.$share->id), $response->json('url'));
    }

    public function test_it_mints_a_link_for_an_album_and_for_an_artist(): void
    {
        $user = User::factory()->create();
        $album = Collection::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();
        $this->actingAs($user)->postJson('/shares', ['subject' => 'artist', 'id' => $artist->id])->assertOk();

        $this->assertSame($album->id, Share::query()->whereNotNull('collection_id')->sole()->collection_id);
        $this->assertSame($artist->id, Share::query()->whereNotNull('artist_id')->sole()->artist_id);
    }

    public function test_exactly_one_subject_column_is_written(): void
    {
        // The DB CHECK says this too, but only on Postgres — sqlite runs the suite. And a
        // constraint would only turn a bug into a 500 anyway; what matters is that the app
        // fills one FK and leaves the other three alone.
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => $album->id])
            ->assertOk();

        $share = Share::query()->sole();

        $this->assertNull($share->track_id);
        $this->assertNull($share->artist_id);
        $this->assertNull($share->playlist_id);
    }

    public function test_a_link_lives_seven_days(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00');

        $track = Track::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'song', 'id' => $track->id])
            ->assertOk();

        $this->assertTrue(Carbon::parse('2026-08-18 12:00:00')->equalTo(Share::query()->sole()->valid_until));
        // Raw and ISO-8601 on the way out, like every other instant this app sends: the
        // browser formats it in the reader's own locale and timezone.
        $this->assertSame('2026-08-18T12:00:00+00:00', $response->json('validUntil'));
    }

    public function test_pressing_share_twice_hands_back_the_same_link(): void
    {
        $user = User::factory()->create();
        $album = Collection::factory()->create();

        $first = $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();
        $second = $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        $this->assertSame($first->json('url'), $second->json('url'));
        $this->assertDatabaseCount('shares', 1);
    }

    public function test_re_sharing_does_not_extend_the_link(): void
    {
        // The reason reuse is the right default rather than a nicety: if a second press
        // reset the clock, a link that is re-sent every few days would never expire, and
        // the seven-day rule would be a rule about nothing.
        Carbon::setTestNow('2026-08-11 12:00:00');
        $user = User::factory()->create();
        $album = Collection::factory()->create();

        $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        Carbon::setTestNow('2026-08-15 12:00:00');
        $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        $this->assertTrue(Carbon::parse('2026-08-18 12:00:00')->equalTo(Share::query()->sole()->valid_until));
    }

    public function test_an_expired_link_is_replaced_rather_than_handed_back(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00');
        $user = User::factory()->create();
        $album = Collection::factory()->create();

        $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        // Past `valid_until`. The dead row STAYS — the owner should see it in their list and
        // re-mint in one click — so this is a second row, not an update.
        Carbon::setTestNow('2026-08-20 12:00:00');
        $this->actingAs($user)->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        $this->assertDatabaseCount('shares', 2);
        $this->assertSame(1, Share::query()->live()->count());
    }

    public function test_two_users_sharing_the_same_album_get_a_link_each(): void
    {
        // So that one of them revoking theirs cannot break the other's.
        $album = Collection::factory()->create();

        $first = $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();
        $second = $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => $album->id])->assertOk();

        $this->assertNotSame($first->json('url'), $second->json('url'));
        $this->assertDatabaseCount('shares', 2);
    }

    public function test_a_genre_cannot_be_shared(): void
    {
        // Not a permission — there is no such thing as a genre share, and the enum is where
        // that is said. "Listen to this genre" is a different kind of act from "listen to
        // this" (the owner's call, 2026-08-11), and the genre hero renders no button at all.
        $genre = Genre::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'genre', 'id' => $genre->id])
            ->assertJsonValidationErrorFor('subject');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_a_playlist_cannot_be_shared_yet(): void
    {
        // Deferred by the owner (2026-08-11). The COLUMN exists so the table's CHECK was
        // written once; the enum does not, which is what makes this a 422 rather than a
        // subject that would need an ownership check nobody has written.
        $playlist = Playlist::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'playlist', 'id' => $playlist->id])
            ->assertJsonValidationErrorFor('subject');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_an_audiobook_chapter_cannot_be_shared_as_a_song(): void
    {
        // `tracks` is a unified table, so a UUID that resolves is not enough — the same trap
        // AuthorizesMusicTrack exists for on every route under /music.
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'song', 'id' => $chapter->id])
            ->assertJsonValidationErrorFor('id');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_an_audiobook_cannot_be_shared_as_an_album(): void
    {
        // `collections` discriminates the two, and the album page could never have offered
        // this id — so accepting it would mint a link for something with no page behind it.
        $audiobook = Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => $audiobook->id])
            ->assertJsonValidationErrorFor('id');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_an_unknown_id_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'album', 'id' => '00000000-0000-7000-8000-000000000000'])
            ->assertJsonValidationErrorFor('id');

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_a_missing_subject_is_reported_as_a_subject_problem(): void
    {
        // Rather than as a missing row: `rules()` falls back to a shape-only check on `id`
        // when the subject does not name a kind, so the message points at what is wrong.
        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['id' => Track::factory()->create()->id])
            ->assertJsonValidationErrorFor('subject')
            ->assertJsonMissingValidationErrors('id');
    }

    public function test_deleting_the_subject_takes_its_shares_with_it(): void
    {
        // The whole reason for four real FKs rather than a polymorphic pair: a rescan drops
        // rows when files disappear, and a share pointing at nothing is a link that resolves
        // to a page it cannot build.
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/shares', ['subject' => 'song', 'id' => $track->id])
            ->assertOk();

        $track->delete();

        $this->assertDatabaseCount('shares', 0);
    }

    public function test_deleting_the_minter_takes_their_shares_with_it(): void
    {
        // Delete the account and the links it handed out stop working.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/shares', ['subject' => 'song', 'id' => Track::factory()->create()->id])
            ->assertOk();

        $user->delete();

        $this->assertDatabaseCount('shares', 0);
    }
}
