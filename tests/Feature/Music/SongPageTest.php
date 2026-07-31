<?php

namespace Tests\Feature\Music;

use App\Enums\Channel;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One song's detail page (`/music/songs/{song}`, behind auth) — the row-click
 * target of the Songs listing (SongController).
 */
class SongPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $song = Track::factory()->create();

        $this->get("/music/songs/{$song->id}")->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_song_with_its_taxonomy(): void
    {
        $song = Track::factory()->create([
            'name' => 'Lightning Strikes',
            'duration' => 185.4,
            'artist_id' => Artist::factory()->create(['name' => 'The Storm'])->id,
            'collection_id' => Collection::factory()->create(['name' => 'Thunder Road', 'year' => 1994])->id,
            'genre_id' => Genre::factory()->create(['name' => 'Post-Rock'])->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Songs/Song/SongPage')
                ->where('song.id', $song->id)
                ->where('song.name', 'Lightning Strikes')
                ->where('song.artist', 'The Storm')
                ->where('song.album', 'Thunder Road')
                ->where('song.year', 1994)
                ->where('song.genre', 'Post-Rock')
                // Seconds as stored — the page clocks them to 3:05, the same way
                // the listing's duration column does (both call formatClock).
                ->where('song.duration', 185.4)
            );
    }

    public function test_the_artist_fact_carries_a_link_to_the_artist_page(): void
    {
        // The second of the two facts on this page that lead somewhere. Same contract as
        // the album below: the server decides, the page renders a filled clickable tile
        // when handed a URL and a plain fact when not — so this prop IS the feature.
        $artist = Artist::factory()->create(['name' => 'The Storm']);
        $song = Track::factory()->create(['artist_id' => $artist->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.artist', 'The Storm')
                ->where('song.artistUrl', "/music/artists/{$artist->id}")
            );
    }

    public function test_a_song_crediting_no_artist_gets_no_artist_link(): void
    {
        // No performer tag, no URL — and therefore no dead link and no filled tile.
        $song = Track::factory()->create(['artist_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.artist', null)
                ->where('song.artistUrl', null)
            );
    }

    public function test_the_genre_fact_carries_a_link_to_the_genre_page(): void
    {
        // The third and last of this page's facts that names something with a page of its
        // own. Same contract as the other two: the server decides, the page renders a
        // filled clickable tile when handed a URL and a plain fact when not.
        $genre = Genre::factory()->create(['name' => 'Post-Rock']);
        $song = Track::factory()->create(['genre_id' => $genre->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.genre', 'Post-Rock')
                ->where('song.genreUrl', "/music/genres/{$genre->id}")
            );
    }

    public function test_a_song_with_no_genre_gets_no_genre_link(): void
    {
        // Untagged genre frame, no URL — and therefore no dead link and no filled tile.
        $song = Track::factory()->create(['genre_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.genre', null)
                ->where('song.genreUrl', null)
            );
    }

    public function test_the_album_fact_carries_a_link_to_the_albums_page(): void
    {
        // One of the two facts on this page that lead somewhere (the artist above is the
        // other), and the server decides that — the page renders a filled, clickable tile
        // when handed a URL and a plain fact when not, so this prop IS the feature.
        $album = Collection::factory()->create(['name' => 'Thunder Road']);
        $song = Track::factory()->create(['collection_id' => $album->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.album', 'Thunder Road')
                ->where('song.albumUrl', "/music/albums/{$album->id}")
            );
    }

    public function test_a_song_under_no_album_gets_no_album_link(): void
    {
        // No album, no URL — and therefore no dead link and no filled tile on the page.
        $song = Track::factory()->create(['collection_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.album', null)
                ->where('song.albumUrl', null)
            );
    }

    public function test_untagged_fields_come_through_as_null_rather_than_failing(): void
    {
        // A music file whose tags named no album/genre: the FKs are nullable, and
        // the page drops the rows instead of rendering empty ones.
        $song = Track::factory()->create([
            'collection_id' => null,
            'genre_id' => null,
            'duration' => null,
            'composer' => null,
            'publisher' => null,
            'codec' => null,
            'channel' => null,
            'sample_rate' => null,
            'bit_rate' => null,
            'size' => null,
            'modified_at' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.album', null)
                ->where('song.genre', null)
                ->where('song.year', null)
                ->where('song.duration', null)
                ->where('song.composer', null)
                ->where('song.publisher', null)
                ->where('song.codec', null)
                ->where('song.channel', null)
                ->where('song.sampleRate', null)
                ->where('song.bitRate', null)
                ->where('song.sizeBytes', null)
                ->where('song.modifiedAt', null)
                // No collection ⇒ nothing to count a track/disc position against;
                // a "2/1" would be worse than an omitted denominator.
                ->where('song.trackTotal', null)
                ->where('song.discTotal', null)
            );
    }

    public function test_the_technical_and_file_facts_are_passed_raw_for_the_page_to_format(): void
    {
        // Sizes, rates and timestamps go over unformatted: the page formats them
        // against the viewer's locale (SongController's docblock).
        $song = Track::factory()->create([
            'composer' => 'Wendy Carlos',
            'publisher' => 'Ableton Records',
            'codec' => 'MPEG1 L3',
            'channel' => Channel::JointStereo,
            'sample_rate' => 44100,
            'bit_rate' => 320000,
            'vbr' => true,
            'cover' => true,
            'size' => 7_340_032,
            'modified_at' => '2026-05-04 21:13:07',
            'path' => 'The Storm/Thunder Road/02 - Lightning Strikes.mp3',
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.composer', 'Wendy Carlos')
                ->where('song.publisher', 'Ableton Records')
                ->where('song.codec', 'MPEG1 L3')
                // The enum's raw value — the page translates it via music.channel.*.
                ->where('song.channel', 'joint_stereo')
                ->where('song.sampleRate', 44100)
                ->where('song.bitRate', 320000)
                ->where('song.vbr', true)
                ->where('song.cover', true)
                ->where('song.sizeBytes', 7_340_032)
                ->where('song.modifiedAt', '2026-05-04T21:13:07+00:00')
                ->where('song.path', 'The Storm/Thunder Road/02 - Lightning Strikes.mp3')
                ->has('song.addedAt')
            );
    }

    public function test_track_and_disc_totals_count_the_songs_album(): void
    {
        $album = Collection::factory()->create();

        // A two-disc album: 3 tracks on disc 1, 2 on disc 2. The song under test
        // is disc 1 track 2, so it reads "2/3" and "1/2".
        $song = Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 2]);
        Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 1]);
        Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 3]);
        Track::factory()->count(2)->create(['collection_id' => $album->id, 'disc' => 2]);

        // A different album's tracks must not leak into either total.
        Track::factory()->count(4)->create(['disc' => 1]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.track', 2)
                ->where('song.trackTotal', 3)
                ->where('song.disc', 1)
                ->where('song.discTotal', 2)
            );
    }

    public function test_a_single_disc_album_reports_one_disc_and_untagged_discs_group_together(): void
    {
        $album = Collection::factory()->create();
        $song = Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 1]);
        Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 2]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            // The page hides the disc row on anything but a real multi-disc set.
            ->assertInertia(fn (Assert $page) => $page->where('song.discTotal', 1)->where('song.trackTotal', 2));

        // An album whose files carry no disc tag at all: the siblings group on
        // `disc IS NULL` (a `= NULL` comparison would total 0), and COUNT(DISTINCT
        // disc) skips the nulls, so the disc count is 0 — read as "no disc info".
        $untagged = Collection::factory()->create();
        $first = Track::factory()->create(['collection_id' => $untagged->id, 'disc' => null, 'track' => 1]);
        Track::factory()->count(2)->create(['collection_id' => $untagged->id, 'disc' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$first->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('song.trackTotal', 3)->where('song.discTotal', 0));
    }

    public function test_an_audiobook_chapter_is_not_reachable_as_a_song(): void
    {
        // Tracks are one table for music and audiobook chapters, so without the
        // controller's type check a chapter would render happily under /music/.
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}")
            ->assertNotFound();
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $user = User::factory()->create();

        // A well-formed UUID that isn't in the table (model binding misses)…
        $this->actingAs($user)
            ->get('/music/songs/'.fake()->uuid())
            ->assertNotFound();

        // …and something that isn't a UUID at all, which the route's whereUuid
        // rejects before any binding runs.
        $this->actingAs($user)
            ->get('/music/songs/not-a-uuid')
            ->assertNotFound();
    }
}
