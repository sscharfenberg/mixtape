<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One album's detail page (`/music/albums/{album}`, behind auth) — the scaffold the
 * Albums listing's rows lead to.
 *
 * The page itself is still a hero and a "coming soon" line, so what is worth testing
 * is what the controller decides: the container's own totals (which must agree with
 * what the listing reports for the same album), the type guard that keeps the other
 * two collection kinds off this route, and the cover URL being decided without any
 * extraction.
 */
class AlbumPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $album = Collection::factory()->create();

        $this->get("/music/albums/{$album->id}")->assertRedirect('/login');
    }

    public function test_it_renders_the_album_with_its_container_totals(): void
    {
        $album = Collection::factory()->create([
            'name' => 'Mellon Collie and the Infinite Sadness',
            'year' => 1995,
            'album_artist_id' => Artist::factory()->create(['name' => 'The Smashing Pumpkins'])->id,
        ]);

        // Two discs, three tracks, a fractional total — the same shape the listing's
        // aggregates are asserted on, so the two pages can be compared by eye.
        foreach ([[1, 100.5], [1, 100.0], [2, 71.0]] as $index => [$disc, $duration]) {
            Track::factory()->create([
                'collection_id' => $album->id,
                'disc' => $disc,
                'track' => $index + 1,
                'duration' => $duration,
                'cover' => false,
                'modified_at' => $index === 2 ? '2024-06-07 08:09:10' : '2019-01-02 03:04:05',
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Albums/Album/AlbumPage')
                ->where('album.name', 'Mellon Collie and the Infinite Sadness')
                ->where('album.artist', 'The Smashing Pumpkins')
                ->where('album.year', 1995)
                ->where('album.songs', 3)
                ->where('album.discs', 2)
                ->where('album.duration', 271.5)
                // The NEWEST file's mtime, not the first one's.
                ->where('album.modifiedAt', fn (?string $iso) => str_starts_with((string) $iso, '2024-06-07T08:09:10'))
                ->where('album.coverUrl', null)
            );
    }

    public function test_an_untagged_rip_still_reports_one_disc(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->count(2)->create(['collection_id' => $album->id, 'disc' => null, 'cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('album.discs', 1));
    }

    public function test_an_album_with_no_tracks_left_does_not_break_the_page(): void
    {
        // The scanner prunes empty collections, but a page must not 500 in the window
        // where one exists — sum() over no rows is null, count() is 0.
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.songs', 0)
                ->where('album.discs', 1)
                ->where('album.duration', null)
                ->where('album.modifiedAt', null)
                ->where('album.coverUrl', null)
            );
    }

    public function test_an_audiobook_is_not_reachable_as_an_album(): void
    {
        $audiobook = Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$audiobook->id}")
            ->assertNotFound();
    }

    public function test_the_cover_url_is_sent_without_extracting_anything(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.coverUrl', "/music/albums/{$album->id}/cover")
            );

        // The page only LINKS the cover; nothing may have been decoded or cached yet.
        $this->assertDirectoryDoesNotExist(storage_path('app/private/covers'));
    }
}
