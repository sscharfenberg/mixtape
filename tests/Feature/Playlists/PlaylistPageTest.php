<?php

namespace Tests\Feature\Playlists;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Play;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One playlist's detail page (`/playlists/{playlist}`, behind auth) — the props
 * PlaylistController hands it.
 *
 * Two things here are not cosmetic. The first is OWNERSHIP, and specifically its SHAPE: a
 * stranger's playlist must answer 404 rather than 403, because this instance is deliberately
 * reachable from the internet and shared with family and friends, and a 403 would confirm
 * that a guessed UUID names a real playlist. The second is the ORDER: a playlist IS its
 * running order, so a page that renders it in any other is showing the wrong thing.
 *
 * The rest is the payload contract the page depends on — every row a complete queue entry
 * (its buttons play it with no round trip) plus the two extras a row shows, the hero's cover
 * fan being one sleeve per ALBUM rather than per track, and its four facts agreeing with the
 * ones the LISTING sends for the same playlist (this page counts them in PHP over entries it
 * has already loaded, where PlaylistsController aggregates them in SQL — two code paths that
 * must answer the same, including on the null-versus-zero edges).
 */
class PlaylistPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $playlist = Playlist::factory()->create();

        $this->get("/playlists/{$playlist->id}")->assertRedirect('/login');
    }

    public function test_a_stranger_gets_a_404_rather_than_a_403(): void
    {
        /*
         * The disclosure test. A 403 says "this playlist exists but is not yours", which is
         * enough to walk the id space and learn what other people keep — see
         * AuthorizesPlaylistOwnership::failedAuthorization.
         */
        $playlist = Playlist::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->get("/playlists/{$playlist->id}")
            ->assertNotFound();
    }

    public function test_an_unknown_id_is_a_404(): void
    {
        // The same answer as a stranger's playlist, which is the point of choosing it.
        $this->actingAs(User::factory()->create())
            ->get('/playlists/'.fake()->uuid())
            ->assertNotFound();
    }

    public function test_a_non_uuid_never_reaches_model_binding(): void
    {
        // `whereUuid` on the route, so "create" and "order" keep matching their own routes
        // rather than being read as a playlist id.
        $this->actingAs(User::factory()->create())
            ->get('/playlists/not-a-uuid')
            ->assertNotFound();
    }

    public function test_the_owner_gets_the_page_and_its_name(): void
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Sunday morning']);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Playlists/Playlist/PlaylistPage')
                ->where('playlist.id', $playlist->id)
                ->where('playlist.name', 'Sunday morning')
                ->has('tracks', 0)
                ->has('covers', 0)
            );
    }

    public function test_the_hero_carries_the_owners_blurb_and_null_when_there_is_none(): void
    {
        // Null rather than "": the hero renders no paragraph at all for one, and an empty one
        // for the other — and the form already stores a blank description as null.
        $reader = User::factory()->create();
        $described = Playlist::factory()->create([
            'user_id' => $reader->id, 'name' => 'A', 'description' => 'Quiet things.',
        ]);
        $bare = Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'B', 'description' => null]);

        $this->actingAs($reader)
            ->get("/playlists/{$described->id}")
            ->assertInertia(fn (Assert $page) => $page->where('playlist.description', 'Quiet things.'));

        $this->actingAs($reader)
            ->get("/playlists/{$bare->id}")
            ->assertInertia(fn (Assert $page) => $page->where('playlist.description', null));
    }

    public function test_the_hero_counts_the_playlist_s_plays(): void
    {
        /*
         * Counted through the PIVOT, which is the only structural difference from the other
         * four subjects — theirs are a column on `tracks`, so a play reaches them by a key it
         * already carries. And with NO type clause: a playlist may mix music with audiobook
         * chapters and its "Titel" tile counts both, so a plays tile that counted only music
         * would be arithmetic a reader cannot reproduce.
         */
        $playlist = $this->ownedPlaylist($reader);
        $stranger = User::factory()->create();
        $track = Track::factory()->create();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id]);

        Play::factory()->count(2)->create(['user_id' => $reader->id, 'track_id' => $track->id]);
        Play::factory()->create(['user_id' => $stranger->id, 'track_id' => $track->id]);
        // A play of something that is NOT in this playlist must not be counted.
        Play::factory()->create(['user_id' => $reader->id, 'track_id' => Track::factory()->create()->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 2)
                ->where('plays.others', 1)
            );
    }

    public function test_a_track_listed_twice_counts_one_listen_once(): void
    {
        /*
         * ONE LISTEN COUNTS ONCE, even for a track the playlist holds twice. Join the pivot and
         * you get a row per ENTRY, so a song sitting in the playlist twice doubles every one of
         * its plays. The argument for that reading — "somebody who put a song in twice hears it
         * twice" — is true of playing the list THROUGH, which writes two rows in `plays` and
         * counts two either way. It is not true of THIS fixture, which is a single listen: one
         * row in `plays`, against a tile that would answer "2" to one press of play.
         *
         * These figures are counts of listening EVENTS everywhere else in PlayCounts, and the
         * pivot is read as a set of track ids, so the shape cannot double count rather than
         * having to remember not to.
         */
        $playlist = $this->ownedPlaylist($reader);
        $track = Track::factory()->create();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 0]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1]);
        Play::factory()->create(['user_id' => $reader->id, 'track_id' => $track->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 1)
                // …and the ENTRY count is untouched: the list still holds two of them. The two
                // tiles measure different things now, which is the point — one is about the list,
                // the other about listening.
                ->where('playlist.tracks', 2)
            );
    }

    public function test_a_row_carries_the_file_path_the_client_sorts_on(): void
    {
        // The only reason a path reaches the client: "sort by path" is then something the page
        // does to the list in front of it rather than something it waits for the server to
        // answer. Area-relative, exactly as stored.
        $playlist = $this->ownedPlaylist($reader);
        $track = Track::factory()->create(['path' => 'Radiohead/OK Computer/01 Airbag.mp3']);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tracks.0.path', 'Radiohead/OK Computer/01 Airbag.mp3')
            );
    }

    public function test_it_sends_the_configured_export_prefix(): void
    {
        // The modal's field opens with it. Config rather than a literal in the page, because it
        // describes the machine that will PLAY the file.
        config(['mixtape.playlists.export.path_prefix' => '/mnt/music']);
        $playlist = $this->ownedPlaylist($reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page->where('exportPrefix', '/mnt/music'));
    }

    public function test_the_hero_carries_the_same_four_facts_the_listing_does(): void
    {
        /*
         * Counted over the entries rather than aggregated in SQL, because this page has
         * already loaded them — so the risk this guards is not a wrong query but a hero that
         * DISAGREES with the list printed under it. Fractional durations on purpose: the sum
         * is deliberately not a whole number, which is the only way to see raw seconds went
         * over rather than something already rounded.
         */
        $playlist = $this->ownedPlaylist($reader);
        PlaylistTrack::factory()->count(3)->create([
            'playlist_id' => $playlist->id,
            'track_id' => fn (): string => Track::factory()->create(['duration' => 100.25])->id,
        ]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlist.tracks', 3)
                ->where('playlist.duration', 300.75)
                // Raw ISO-8601: the page formats it against the viewer's locale and timezone,
                // neither of which the server knows.
                ->where('playlist.createdAt', $playlist->created_at->toIso8601String())
                ->has('tracks', 3)
            );
    }

    public function test_an_empty_playlist_reports_no_duration_rather_than_zero(): void
    {
        // The page hangs a tile on the distinction: "0 Sekunden" beside a track count of 0
        // says nothing twice. Null is also what the LISTING sends (SQL's SUM over no rows),
        // and the two pages must not describe the same playlist differently.
        $playlist = $this->ownedPlaylist($reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlist.tracks', 0)
                ->where('playlist.duration', null)
            );
    }

    public function test_a_playlist_of_untagged_files_reports_no_duration_either(): void
    {
        // SQL's SUM over rows that are all NULL is NULL too, so the PHP sum here has to agree
        // — otherwise this page would claim "0 Sekunden" where the listing prints no tile.
        $playlist = $this->ownedPlaylist($reader);
        PlaylistTrack::factory()->count(2)->create([
            'playlist_id' => $playlist->id,
            'track_id' => fn (): string => Track::factory()->create(['duration' => null])->id,
        ]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlist.tracks', 2)
                ->where('playlist.duration', null)
            );
    }

    public function test_a_playlist_nothing_has_happened_to_reports_no_update(): void
    {
        /*
         * `created_at` and `updated_at` are written from ONE instant on insert, so they are
         * exactly equal until something moves one of them — and the hero hangs its "changed"
         * tile on this being null. Were the controller to ship `updated_at` unconditionally,
         * every brand-new playlist would claim it had been changed the moment it was made.
         * The listing makes the same call; the two must not disagree.
         */
        $playlist = $this->ownedPlaylist($reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page->where('playlist.updatedAt', null));
    }

    public function test_adding_a_track_counts_as_changing_the_playlist(): void
    {
        // PlaylistTrack::$touches is what makes the "changed" fact say something about the
        // thing a listener actually changes, which is what is IN the playlist.
        $playlist = $this->ownedPlaylist($reader);

        $this->travel(1)->minute();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlist.updatedAt', $playlist->fresh()->updated_at->toIso8601String())
            );
    }

    public function test_entries_come_back_in_the_readers_own_order(): void
    {
        /*
         * The whole point of the page. `position` is the reader's running order, and it is
         * NOT the order the rows were inserted in — here the last-created entry is first.
         */
        $playlist = $this->ownedPlaylist($reader);

        $this->entry($playlist, 'Third', 2);
        $this->entry($playlist, 'First', 0);
        $this->entry($playlist, 'Second', 1);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tracks.0.name', 'First')
                ->where('tracks.1.name', 'Second')
                ->where('tracks.2.name', 'Third')
            );
    }

    public function test_a_row_is_a_complete_queue_entry(): void
    {
        /*
         * Every field usePlayerQueue's `QueueTrack` reads, because the row's own play button
         * hands this straight to the player — a missing one is a track that looks playable
         * and does nothing. Shaped by QueuePayload::entry, the same definition the four Music
         * pages use.
         */
        $playlist = $this->ownedPlaylist($reader);
        $artist = Artist::factory()->create(['name' => 'Radiohead']);
        $album = Collection::factory()->create(['name' => 'OK Computer', 'year' => 1997]);
        $track = Track::factory()->create([
            'name' => 'Airbag',
            'artist_id' => $artist->id,
            'collection_id' => $album->id,
            'duration' => 284.5,
            'cover' => true,
        ]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tracks.0.id', $track->id)
                ->where('tracks.0.name', 'Airbag')
                ->where('tracks.0.artist', 'Radiohead')
                ->where('tracks.0.album', 'OK Computer')
                // Raw seconds, not a clock: the page formats against the viewer's locale.
                ->where('tracks.0.duration', 284.5)
                ->where('tracks.0.coverUrl', "/music/songs/{$track->id}/cover")
                ->where('tracks.0.href', "/music/songs/{$track->id}")
                ->where('tracks.0.streamUrl', "/music/songs/{$track->id}/stream")
                // …plus the two a row shows and a queue entry has no use for.
                ->where('tracks.0.year', 1997)
                ->has('tracks.0.entryId')
            );
    }

    public function test_a_row_is_keyed_by_its_entry_rather_than_its_track(): void
    {
        // The same track may sit in a playlist twice, so `id` cannot key the list — two rows
        // sharing a Vue key is a rendering fault, not a data one.
        $playlist = $this->ownedPlaylist($reader);
        $track = Track::factory()->create();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 0]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(function (Assert $page) {
                $page->has('tracks', 2);

                $tracks = $page->toArray()['props']['tracks'];
                $this->assertSame($tracks[0]['id'], $tracks[1]['id']);
                $this->assertNotSame($tracks[0]['entryId'], $tracks[1]['entryId']);
            });
    }

    public function test_an_untagged_row_reports_nulls_rather_than_placeholders(): void
    {
        // The page drops a chip on null; a "" or a 0 would print an empty pill or a fake year.
        $playlist = $this->ownedPlaylist($reader);
        $track = Track::factory()->create([
            'artist_id' => null,
            'collection_id' => null,
            'duration' => null,
            'cover' => false,
        ]);
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id, 'track_id' => $track->id]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tracks.0.artist', null)
                ->where('tracks.0.album', null)
                ->where('tracks.0.year', null)
                ->where('tracks.0.duration', null)
                ->where('tracks.0.coverUrl', null)
            );
    }

    public function test_an_audiobook_chapter_in_a_playlist_is_not_filtered_out(): void
    {
        /*
         * No type filter, unlike every Music page: the unified `tracks` table exists so a
         * playlist can mix music with audiobook chapters, and dropping one would leave a row
         * the reader added simply missing, with nothing to explain it.
         */
        $playlist = $this->ownedPlaylist($reader);
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => Track::factory()->audiobook()->create(['name' => 'Chapter One'])->id,
        ]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('tracks', 1)
                ->where('tracks.0.name', 'Chapter One')
            );
    }

    public function test_the_cover_fan_shows_one_sleeve_per_album(): void
    {
        /*
         * A cover URL here is per TRACK, so ten songs off one record are ten different URLs
         * pointing at the same picture. Three identical sleeves read as a rendering fault,
         * which is why the fan dedupes by album — the genre page gets this for free because
         * it fans ALBUM covers.
         */
        $playlist = $this->ownedPlaylist($reader);
        $album = Collection::factory()->create();
        foreach (range(1, 5) as $position) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'position' => $position,
                'track_id' => Track::factory()->create(['collection_id' => $album->id, 'cover' => true])->id,
            ]);
        }

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page->has('covers', 1));
    }

    public function test_the_cover_fan_holds_at_most_three_sleeves(): void
    {
        $playlist = $this->ownedPlaylist($reader);
        foreach (range(1, 6) as $position) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'position' => $position,
                'track_id' => Track::factory()->create([
                    'collection_id' => Collection::factory(),
                    'cover' => true,
                ])->id,
            ]);
        }

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page->has('covers', 3));
    }

    public function test_tracks_without_artwork_are_left_out_of_the_fan(): void
    {
        // Dropped rather than fanned as placeholders: two sleeves and a grey square looks
        // broken, where two sleeves looks like two records. An empty list is what makes the
        // hero draw its dashed "no artwork on file" square instead.
        $playlist = $this->ownedPlaylist($reader);
        PlaylistTrack::factory()->count(3)->create([
            'playlist_id' => $playlist->id,
            'track_id' => fn (): string => Track::factory()->create(['cover' => false])->id,
        ]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('tracks', 3)
                ->has('covers', 0)
            );
    }

    public function test_it_does_not_leak_another_playlists_entries(): void
    {
        $playlist = $this->ownedPlaylist($reader);
        $other = Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Something else']);
        PlaylistTrack::factory()->create([
            'playlist_id' => $other->id,
            'track_id' => Track::factory()->create(['name' => 'Not in it'])->id,
        ]);
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => Track::factory()->create(['name' => 'In it'])->id,
        ]);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('tracks', 1)
                ->where('tracks.0.name', 'In it')
            );
    }

    /**
     * A playlist owned by a fresh reader, handing the reader back through the parameter.
     *
     * By reference rather than a returned pair because every test here needs both and
     * `[$reader, $playlist] = …` at the top of a dozen methods reads worse than one call.
     */
    private function ownedPlaylist(?User &$reader): Playlist
    {
        $reader = User::factory()->create();

        return Playlist::factory()->create(['user_id' => $reader->id]);
    }

    /** One entry at a known position, over a track with a known title. */
    private function entry(Playlist $playlist, string $title, int $position): PlaylistTrack
    {
        return PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'position' => $position,
            'track_id' => Track::factory()->create(['name' => $title])->id,
        ]);
    }
}
