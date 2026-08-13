<?php

namespace Tests\Feature\Shares;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use App\Services\Shares\ShareGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as Support;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * `GET /s/{share}` — the page somebody WITHOUT AN ACCOUNT lands on (docs/sharing.md).
 *
 * THIS IS THE APP'S ONLY UNAUTHENTICATED VIEW OF THE LIBRARY, so what is pinned here is
 * mostly about the edges of the grant rather than about the page:
 *
 *   - IT WORKS WITH NO SESSION AT ALL. The whole feature is that sentence.
 *   - THE PAGE AND THE STREAM GUARD DESCRIBE THE SAME SET. Both go through ShareGrant, and
 *     the artist case is where that would otherwise drift — `tracks.artist_id` is NOT
 *     `collections.album_artist_id`, so a page built from one and a guard written from the
 *     other differ on exactly the records with a guest appearance on them. That test is the
 *     reason ShareGrant exists.
 *   - EVERY URL IT HANDS OUT STAYS INSIDE `/s/`. A single `/music/…` URL on a queue entry is
 *     a row that plays for the owner testing it and bounces a guest to the login form.
 *   - EXPIRED RENDERS, REVOKED 404s. The difference follows from revoke being a DELETE, and
 *     it is a decision rather than an accident, so it is written down twice: here and in the
 *     controller.
 *
 * The media routes have a file of their own (ShareMediaTest), and minting has CreateShareTest.
 */
class ShowShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_with_the_link_sees_the_song_it_shares(): void
    {
        $artist = Artist::factory()->create(['name' => 'Godspeed You! Black Emperor']);
        $album = Collection::factory()->create(['name' => 'Lift Your Skinny Fists', 'year' => 2000]);
        $song = Track::factory()->create([
            'name' => 'Storm',
            'artist_id' => $artist->id,
            'collection_id' => $album->id,
        ]);

        $share = Share::factory()->ofSong($song)->create();

        // No `actingAs` anywhere in this file's happy paths, deliberately: the feature IS
        // that this answers without a session.
        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Share/SharePage')
                ->where('share.kind', 'song')
                ->where('share.expired', false)
                ->where('subject.name', 'Storm')
                ->where('subject.artist', 'Godspeed You! Black Emperor')
                ->where('subject.album', 'Lift Your Skinny Fists')
                ->where('subject.year', 2000)
                ->where('subject.songs', 1)
                ->has('tracks', 1)
                ->where('tracks.0.id', $song->id)
            );
    }

    public function test_the_hero_says_what_kind_of_music_a_link_holds(): void
    {
        // ADDED 2026-08-13 (the owner): the fact that means most to a recipient who does not
        // know the band, and the one the album page carried while the share hero did not.
        //
        // IT IS THE SAME DERIVED ANSWER the library's own pages give — genre is tagged per
        // TRACK, so an album has none of its own and "mostly this" is DominantGenre's, tie-break
        // included. A guest being told a different genre than the owner sees on the album page
        // is the failure this asserts against, which is why the fixture is the dominant-genre
        // shape (a majority and an incidental) rather than one tagged track.
        $album = Collection::factory()->create();
        $mostly = Genre::factory()->create(['name' => 'Doom']);
        $incidental = Genre::factory()->create(['name' => 'Polka']);
        Track::factory()->count(3)->create(['collection_id' => $album->id, 'genre_id' => $mostly->id]);
        Track::factory()->create(['collection_id' => $album->id, 'genre_id' => $incidental->id]);

        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            // No `genreUrl` beside it, unlike every other page that draws this tile: a genre's
            // page is under `/music`, and a guest following it would land on the login form.
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subject.genre', 'Doom')
                ->missing('subject.genreUrl')
            );
    }

    public function test_a_link_whose_music_is_untagged_gets_no_genre_at_all(): void
    {
        // Null in, null out — the page drops the tile rather than drawing an empty chip.
        $song = Track::factory()->create(['genre_id' => null]);
        $share = Share::factory()->ofSong($song)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subject.genre', null));
    }

    public function test_an_album_share_lists_that_album_and_no_other(): void
    {
        $album = Collection::factory()->create(['name' => 'Ágætis byrjun']);
        $mine = Track::factory()->count(3)->create(['collection_id' => $album->id]);
        $theirs = Track::factory()->create();

        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('share.kind', 'album')
                ->where('subject.name', 'Ágætis byrjun')
                ->where('subject.songs', 3)
                ->has('tracks', 3)
                // A Collection, not an array: the fluent assertion hands the closure whatever
                // the prop decoded to, and a nested list arrives wrapped.
                ->where('tracks', fn (Support $tracks) => $tracks->pluck('id')->sort()->values()->all()
                    === $mine->pluck('id')->sort()->values()->all()
                    && ! $tracks->pluck('id')->contains($theirs->id)
                )
            );
    }

    /**
     * THE ARTIST TRAP, which is the one bug this whole design is arranged around.
     *
     * An artist's page draws its album grid from `collections.album_artist_id` and its
     * playable queue from `tracks.artist_id`. Those overlap and neither contains the other: a
     * compilation holds an artist's track without being their album, and an album credited to
     * them can hold a guest track credited to somebody else. A share must grant the second
     * set — what "play this artist" means — and the page must show exactly that, or a guest
     * gets a row that 404s on the one record with a featured guest on it.
     *
     * Asserted from BOTH ends here: the page's rows, and the stream guard's answer for each
     * of the three tracks. They are the same set only because they are the same query.
     */
    public function test_an_artist_share_grants_their_tracks_not_their_albums(): void
    {
        $artist = Artist::factory()->create();
        $guest = Artist::factory()->create();

        // Their own album, and on it a track credited to somebody else.
        $ownAlbum = Collection::factory()->create(['album_artist_id' => $artist->id]);
        $ownTrack = Track::factory()->create(['collection_id' => $ownAlbum->id, 'artist_id' => $artist->id]);
        $guestTrack = Track::factory()->create(['collection_id' => $ownAlbum->id, 'artist_id' => $guest->id]);

        // A compilation credited to nobody, carrying one of their tracks.
        $compilation = Collection::factory()->create(['album_artist_id' => null]);
        $onCompilation = Track::factory()->create(['collection_id' => $compilation->id, 'artist_id' => $artist->id]);

        $share = Share::factory()->ofArtist($artist)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('share.kind', 'artist')
                ->where('subject.songs', 2)
                ->has('tracks', 2)
                ->where('tracks', fn (Support $tracks) => $tracks->pluck('id')->sort()->values()->all()
                    === collect([$ownTrack->id, $onCompilation->id])->sort()->values()->all()
                )
            );

        // …and the guard agrees, track for track. This is the assertion that would fail if
        // the page and the stream ever stopped sharing one query.
        $this->get("/s/{$share->id}/tracks/{$ownTrack->id}/stream")->assertNotFound(); // no file on disk
        $this->get("/s/{$share->id}/tracks/{$onCompilation->id}/stream")->assertNotFound();
        $this->get("/s/{$share->id}/tracks/{$guestTrack->id}/stream")->assertNotFound();

        // Both of the first two 404 for a MISSING FILE rather than for the guard, which a
        // status alone cannot tell apart — so the guard itself is asked directly.
        $grant = ShareGrant::for($share);
        $this->assertTrue($grant->contains($ownTrack->id));
        $this->assertTrue($grant->contains($onCompilation->id));
        $this->assertFalse($grant->contains($guestTrack->id));
    }

    public function test_every_url_it_hands_a_guest_stays_inside_the_share(): void
    {
        $album = Collection::factory()->create();
        $song = Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);
        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tracks.0.streamUrl', "/s/{$share->id}/tracks/{$song->id}/stream")
                ->where('tracks.0.coverUrl', "/s/{$share->id}/tracks/{$song->id}/cover")
                // `href` is the one that would be missed: left at its default it points at
                // /music/songs/{id}, which for a guest is a bounce to the login form.
                ->where('tracks.0.href', "/s/{$share->id}")
            );
    }

    public function test_a_track_with_no_artwork_is_sent_without_a_cover_url(): void
    {
        $album = Collection::factory()->create();
        $song = Track::factory()->create(['collection_id' => $album->id, 'cover' => false]);
        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            // Null rather than a URL that would 404: the page draws a placeholder for it, and
            // an <img> pointed at a missing cover is a broken picture on every row.
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tracks.0.coverUrl', null));

        $this->assertNotNull($song->id);
    }

    public function test_an_expired_link_still_says_what_it_was_for_but_plays_nothing(): void
    {
        $song = Track::factory()->create(['name' => 'Moya']);
        $share = Share::factory()->ofSong($song)->expired()->create();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Share/SharePage')
                ->where('share.expired', true)
                // The name survives so the reader can say WHAT they are asking to be sent
                // again. The only person who can reach this URL is someone who was given it.
                ->where('subject.name', 'Moya')
                // Nothing to play, and no cover URL either — the media routes refuse a dead
                // link, so sending one would only put a broken image on the page.
                ->has('tracks', 0)
                ->where('subject.coverUrl', null)
            );
    }

    public function test_a_revoked_link_is_an_ordinary_404(): void
    {
        $share = Share::factory()->ofSong()->create();
        $id = $share->id;

        // Revoking is a DELETE, which is why this differs from expiry: there is no row left
        // to render an explanation from, and "no such link" is indistinguishable from a typo.
        $share->delete();

        $this->get("/s/{$id}")->assertNotFound();
    }

    public function test_a_link_that_is_not_a_uuid_never_reaches_model_binding(): void
    {
        $this->get('/s/not-a-uuid')->assertNotFound();
    }

    /**
     * A PLAYLIST SHARE PLAYS THE PLAYLIST'S OWN ORDER — the one thing about this subject that
     * no other share has to get right, and the reason ShareGrant has a branch for it.
     *
     * Every other kind is served in playing order (album, then disc, then track), which is what
     * `QueuePayload::fromQuery` imposes and what a listener pressing "play this artist" expects.
     * A playlist is a hand-made sequence, so that sort would rewrite it — and nothing would look
     * broken: the guest would simply hear somebody's mix in an order they never chose. The
     * fixture is arranged so the two orders DISAGREE (positions run against the album's own
     * track numbers), because an order test whose two candidate answers coincide proves nothing.
     */
    public function test_a_playlist_share_plays_the_owners_own_order(): void
    {
        $playlist = Playlist::factory()->create();
        $album = Collection::factory()->create(['year' => 2001]);
        $first = Track::factory()->create(['collection_id' => $album->id, 'track' => 1, 'name' => 'Opener']);
        $last = Track::factory()->create(['collection_id' => $album->id, 'track' => 9, 'name' => 'Closer']);

        // Backwards on purpose: track 9 first, track 1 second.
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $last->id, 'position' => 1]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $first->id, 'position' => 2]);

        $share = Share::factory()->create(['playlist_id' => $playlist->id]);

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('share.kind', 'playlist')
                ->where('subject.name', $playlist->name)
                // No artist, album, year or genre: a playlist is a list of other people's
                // records, so none of the four describes it (SharePageController says so too).
                ->where('subject.artist', null)
                ->where('subject.genre', null)
                ->has('tracks', 2)
                ->where('tracks.0.id', $last->id)
                ->where('tracks.1.id', $first->id)
            );
    }

    public function test_a_shared_playlist_follows_its_owners_edits(): void
    {
        // THE OWNER'S REQUIREMENT (2026-08-13): a guest who reloads gets the playlist as it is
        // now. Nothing is copied at mint time — the row holds a `playlist_id` and the grant
        // resolves the pivot on every request — so this is a test that no snapshot crept in.
        $playlist = Playlist::factory()->create();
        $original = Track::factory()->create();
        $entry = PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id, 'track_id' => $original->id, 'position' => 1,
        ]);

        $share = Share::factory()->create(['playlist_id' => $playlist->id]);

        $this->get("/s/{$share->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tracks', 1)->where('subject.songs', 1));

        // The owner adds one and removes the other, exactly as the playlist page would.
        $added = Track::factory()->create();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $added->id, 'position' => 2]);
        $entry->delete();

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tracks', 1)
                ->where('tracks.0.id', $added->id)
                ->where('subject.songs', 1)
            );

        // …and the track that left the playlist stops playing, which is the half a page test
        // cannot see: the media routes ask the same grant.
        $this->get("/s/{$share->id}/tracks/{$original->id}/stream")->assertNotFound();
    }

    public function test_a_playlist_share_keeps_the_audiobook_chapters_its_owner_put_in_it(): void
    {
        // NO TYPE FILTER, unlike an artist share: a reader may deliberately mix music with
        // audiobook chapters in one playlist — that is what the unified `tracks` table is for —
        // and filtering here would silently drop entries they added themselves. The playlist's
        // own page makes the same choice, and the two have to agree.
        $playlist = Playlist::factory()->create();
        $song = Track::factory()->create();
        $chapter = Track::factory()->audiobook()->create();

        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $song->id, 'position' => 1]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $chapter->id, 'position' => 2]);

        $share = Share::factory()->create(['playlist_id' => $playlist->id]);

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tracks', 2)
                ->where('tracks.1.id', $chapter->id)
            );

        $this->assertSame(TrackType::Audiobook, $chapter->type);
    }

    public function test_a_playlist_share_fans_sleeves_rather_than_claiming_one_cover(): void
    {
        // A playlist is not a record, so there is no single picture to point an <img> at — it
        // borrows a few of its own, exactly as its page does for its owner (ShareArtwork).
        $playlist = Playlist::factory()->create();
        $withArt = Track::factory()->create(['cover' => true, 'collection_id' => Collection::factory()]);

        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $withArt->id, 'position' => 1]);

        $share = Share::factory()->create(['playlist_id' => $playlist->id]);

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subject.coverUrl', null)
                ->has('subject.sleeves', 1)
                ->where('subject.sleeves.0', route('shares.tracks.cover', [$share, $withArt], absolute: false))
            );
    }

    /**
     * An audiobook chapter cannot be reached through a music share, and the reason is worth
     * a test rather than a comment: `collections` is a unified table, so an album share whose
     * grant were merely "this collection's tracks with no further thought" would be right —
     * but a SONG share of a chapter would mint from a page that never offers one. Minting
     * refuses it (CreateShareTest); this checks the serving half stays consistent by granting
     * an audiobook's own tracks when the subject genuinely is that audiobook.
     */
    public function test_an_audiobook_collection_share_grants_its_chapters(): void
    {
        $audiobook = Collection::factory()->audiobook()->create();
        $chapter = Track::factory()->audiobook()->create(['collection_id' => $audiobook->id]);

        $share = Share::factory()->ofAlbum($audiobook)->create();

        $this->assertSame(CollectionType::Audiobook, $audiobook->type);
        $this->assertSame(TrackType::Audiobook, $chapter->type);

        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tracks', 1)->where('tracks.0.id', $chapter->id));
    }

    public function test_a_signed_in_reader_opening_their_own_link_gets_the_same_page(): void
    {
        $user = User::factory()->create();
        $song = Track::factory()->create();
        $share = Share::factory()->ofSong($song)->create(['user_id' => $user->id]);

        // Nothing about this page is gated, so being signed in changes only the header. What
        // it must NOT do is redirect: a reader testing their own link before sending it is
        // the most common first visit any share gets.
        $this->actingAs($user)
            ->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Share/SharePage'));
    }
}
