<?php

namespace Tests\Feature\Shares;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /s/{share}` when the reader ALREADY HAS AN ACCOUNT — the redirect to the subject's own
 * page.
 *
 * A FILE OF ITS OWN, because ShowShareTest's premise is the opposite one and says so: it signs
 * nobody in anywhere, since the feature it covers is that the page answers without a session.
 * Mixing the two would put an `actingAs` in a file whose whole point is their absence.
 *
 * WHY THE REDIRECT EXISTS: the person pasting a link into a chat cannot know whether the reader
 * opening it has an account. The guest page is strictly less than the real one for somebody who
 * does — no breadcrumb, no queue, no search, no way on to the rest of the album — and its player
 * URLs die with the link. So the same URL has to serve both readers, and the one who can be sent
 * somewhere better is.
 *
 * THE PLAYLIST CASE IS WHY THIS IS NOT ONE LINE. Every other subject is library-wide: any
 * account may open any song, album, artist or audiobook. A playlist has an OWNER and
 * `playlists.show` answers 404 to everybody else — so redirecting every signed-in reader would
 * take a link that works and hand its recipient a "not found". That test is the one worth
 * keeping if any of these are ever pruned.
 */
class SignedInShareRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_song_share_sends_a_signed_in_reader_to_the_song(): void
    {
        $song = Track::factory()->create();
        $share = Share::factory()->ofSong($song)->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertRedirect("/music/songs/{$song->id}");
    }

    public function test_an_album_share_sends_them_to_the_album(): void
    {
        $album = Collection::factory()->create();
        $share = Share::factory()->ofAlbum($album)->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertRedirect("/music/albums/{$album->id}");
    }

    public function test_an_artist_share_sends_them_to_the_artist(): void
    {
        $artist = Artist::factory()->create();
        $share = Share::factory()->ofArtist($artist)->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertRedirect("/music/artists/{$artist->id}");
    }

    public function test_an_audiobook_share_sends_them_to_the_audiobook_not_the_album_page(): void
    {
        /*
         * The one that cannot be derived from the FK. An audiobook and an album are both a
         * `collection_id`, so the kind is read off `collections.type` — and the two have
         * different pages, which is exactly the distinction that would be lost by matching on
         * the column instead of the subject.
         */
        $book = Collection::factory()->audiobook()->create();
        $share = Share::factory()->ofAlbum($book)->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertRedirect("/audiobooks/{$book->id}");
    }

    public function test_a_playlist_share_sends_its_owner_to_their_playlist(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();
        $share = Share::factory()->for($owner)->ofPlaylist($playlist)->create();

        $this->actingAs($owner)
            ->get("/s/{$share->id}")
            ->assertRedirect("/playlists/{$playlist->id}");
    }

    public function test_a_playlist_share_still_shows_the_guest_page_to_a_signed_in_stranger(): void
    {
        /*
         * THE ONE THAT MAKES THE RULE CONDITIONAL. Having an account does not give you
         * somebody else's playlist — `playlists.show` 404s — so a redirect here would break a
         * working link for precisely the person it was sent to. The share page is the only
         * page in this app that will show it to them.
         */
        $playlist = Playlist::factory()->create();
        $share = Share::factory()->ofPlaylist($playlist)->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertOk();
    }

    public function test_the_minter_checking_their_own_link_is_redirected_too(): void
    {
        /*
         * THE CASE WITH THE STRONGEST CLAIM TO AN EXCEPTION, and it does not get one:
         * checking a link before sending it is plausibly the most common first visit a share
         * ever gets, and this redirect means the sender cannot see what the recipient will.
         *
         * The alternative is worse. Serving the guest page to the account that minted it
         * makes the page behave differently for one reader — so the "preview" would be the
         * one visit not representative of any real one, since it carries a session the
         * recipient will not have. A private window shows the truth. Given the choice
         * between a preview that lies and no preview, this takes no preview.
         */
        $owner = User::factory()->create();
        $song = Track::factory()->create();
        $share = Share::factory()->for($owner)->ofSong($song)->create();

        $this->actingAs($owner)
            ->get("/s/{$share->id}")
            ->assertRedirect("/music/songs/{$song->id}");
    }

    public function test_an_expired_share_still_redirects_a_signed_in_reader(): void
    {
        /*
         * Expiry is a fact about the LINK, and the link is not what grants a signed-in reader
         * anything — the library is already theirs. Telling them "this expired" about a song
         * they can simply open would be a dead end where the redirect is a working page. A
         * guest still gets the expired page, which the test below holds.
         */
        $song = Track::factory()->create();
        $share = Share::factory()->ofSong($song)->expired()->create();

        $this->actingAs(User::factory()->create())
            ->get("/s/{$share->id}")
            ->assertRedirect("/music/songs/{$song->id}");
    }

    public function test_a_guest_is_not_redirected_anywhere(): void
    {
        // The regression guard on the whole feature: the page must still answer without a
        // session, which is what sharing IS.
        $song = Track::factory()->create();
        $share = Share::factory()->ofSong($song)->create();

        $this->get("/s/{$share->id}")->assertOk();
    }
}
