<?php

namespace Tests\Feature\Playlists;

use App\Models\Collection;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The two props behind "add this to a playlist": the SHARED `playlists` list, and each detail
 * page's `addablePlaylists`.
 *
 * They are a pair by design and tested together for that reason. The shared list is the one
 * copy of the reader's playlists — names, in the order they arranged them — because the play
 * queue's menu can be opened from any page, including pages that know nothing about playlists.
 * A detail page then sends only the IDS it may offer, and narrows that one list rather than
 * carrying a second copy of it. Assert them apart and it would be possible for a page to offer
 * an id the shared list never mentions, which renders as a select with fewer options than the
 * server intended and nothing to say why.
 *
 * The rule the ids encode is "would pressing save do anything": a playlist drops out only when
 * it already holds EVERY track of the subject. That is the generalisation of what a song page
 * needs ("not the playlists that already have this song") to an album, and the case worth
 * pinning is the middle one — a playlist holding some of an album keeps being offered, because
 * the rest is a real addition.
 */
class AddablePlaylistsPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_told_about_no_playlists_at_all(): void
    {
        // Not merely an economy: nothing offers this to a signed-out reader, and the login page
        // has to render on a box whose tables may not exist yet — the same rule the `library`
        // prop beside it records.
        Playlist::factory()->create();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('playlists', []));
    }

    public function test_the_shared_list_is_the_readers_own_in_their_own_order(): void
    {
        $reader = User::factory()->create();
        Playlist::factory()->for($reader)->create(['name' => 'Later', 'position' => 2]);
        Playlist::factory()->for($reader)->create(['name' => 'First', 'position' => 1]);
        // Both pinned to the same position, so the NAME is what breaks the tie — exactly as the
        // listing page sorts. Pinned rather than left to the factory, which randomises `position`
        // (see ReorderPlaylistsTest, where not pinning it cost two failures).
        Playlist::factory()->for($reader)->create(['name' => 'Also first', 'position' => 1]);
        Playlist::factory()->create(['name' => 'Somebody else’s', 'position' => 0]);

        $this->actingAs($reader)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                // A Support Collection rather than an array — `where` hands a closure the value
                // wrapped, which is what the framework does for any list-shaped prop.
                ->where('playlists', fn (SupportCollection $playlists): bool => $playlists->pluck('name')->all() === [
                    'Also first', 'First', 'Later',
                ])
                ->etc());
    }

    public function test_a_song_page_leaves_out_the_playlists_that_already_hold_it(): void
    {
        $reader = User::factory()->create();
        $song = Track::factory()->create();

        $has = Playlist::factory()->for($reader)->create();
        $hasNot = Playlist::factory()->for($reader)->create();
        PlaylistTrack::factory()->create(['playlist_id' => $has->id, 'track_id' => $song->id]);

        $this->actingAs($reader)
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn (Assert $page) => $page->where('addablePlaylists', [$hasNot->id])->etc());
    }

    public function test_an_album_page_keeps_a_playlist_that_holds_only_part_of_it(): void
    {
        $reader = User::factory()->create();
        $album = Collection::factory()->create();
        [$one, $two] = Track::factory()->count(2)->sequence(['track' => 1], ['track' => 2])
            ->create(['collection_id' => $album->id])
            ->all();

        $partial = Playlist::factory()->for($reader)->create(['name' => 'Half of it', 'position' => 0]);
        $complete = Playlist::factory()->for($reader)->create(['name' => 'All of it', 'position' => 1]);

        PlaylistTrack::factory()->create(['playlist_id' => $partial->id, 'track_id' => $one->id]);
        PlaylistTrack::factory()->create(['playlist_id' => $complete->id, 'track_id' => $one->id]);
        PlaylistTrack::factory()->create(['playlist_id' => $complete->id, 'track_id' => $two->id]);

        $this->actingAs($reader)
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('addablePlaylists', [$partial->id])->etc());
    }

    public function test_a_subject_with_nothing_in_it_opens_no_playlist(): void
    {
        // An album of nothing but audiobook chapters resolves to no tracks, so there is nothing
        // to add anywhere — which is what makes the page hide the block rather than offer a
        // save that would do nothing.
        $reader = User::factory()->create();
        Playlist::factory()->for($reader)->create();

        $album = Collection::factory()->create();
        Track::factory()->audiobook()->create(['collection_id' => $album->id]);

        $this->actingAs($reader)
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('addablePlaylists', [])->etc());
    }
}
