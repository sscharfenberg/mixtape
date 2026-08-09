<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `app:playlist` — the only way to build a populated playlist until a song or album page can
 * add to one.
 *
 * The thing worth testing is what makes it different from a seeder: it picks from the tracks
 * that are actually in the database, so on an instance with a scanned collection the rows it
 * adds are real files that really play. A seeder's factory tracks point at paths nothing ever
 * wrote, which is why seeding did not solve this.
 *
 * The rest is the behaviour a command run twice by hand depends on: it APPENDS rather than
 * replacing, it keeps `position` contiguous, and it refuses clearly instead of half-writing
 * when the library is empty or an option is wrong.
 */
class FillPlaylistCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_playlist_and_fills_it_from_the_library(): void
    {
        $user = User::factory()->create(['name' => 'Ashaltiriak']);
        Track::factory()->count(5)->create();

        $this->artisan('app:playlist', ['name' => 'Testliste', '--tracks' => 3])
            ->assertExitCode(0);

        $playlist = Playlist::query()->where('name', 'Testliste')->firstOrFail();
        $this->assertSame($user->id, $playlist->user_id);
        $this->assertCount(3, $playlist->playlistTracks);
        // Real rows from the library, not invented ones.
        $this->assertSame(5, Track::query()->count());
    }

    public function test_a_second_run_appends_rather_than_replacing(): void
    {
        // The command is run by hand, so running it again to lengthen a list is the obvious
        // thing to try — and a replace would silently throw away what was already in there.
        User::factory()->create();
        Track::factory()->count(10)->create();

        $this->artisan('app:playlist', ['name' => 'Testliste', '--tracks' => 3])->assertExitCode(0);
        $this->artisan('app:playlist', ['name' => 'Testliste', '--tracks' => 2])->assertExitCode(0);

        $this->assertSame(1, Playlist::query()->count());
        $this->assertSame(5, PlaylistTrack::query()->count());
    }

    public function test_positions_stay_contiguous_across_runs(): void
    {
        // `position` is meant to be contiguous — the reorder path renumbers the whole set on
        // that assumption — so a second run must continue the numbering, not restart it.
        User::factory()->create();
        Track::factory()->count(10)->create();

        $this->artisan('app:playlist', ['--tracks' => 3])->assertExitCode(0);
        $this->artisan('app:playlist', ['--tracks' => 2])->assertExitCode(0);

        $positions = PlaylistTrack::query()->orderBy('position')->pluck('position')->all();
        $this->assertSame([0, 1, 2, 3, 4], $positions);
    }

    public function test_it_takes_what_the_library_has_when_asked_for_more(): void
    {
        User::factory()->create();
        Track::factory()->count(2)->create();

        $this->artisan('app:playlist', ['--tracks' => 50])->assertExitCode(0);

        $this->assertSame(2, PlaylistTrack::query()->count());
    }

    public function test_it_picks_music_by_default_and_audiobooks_only_when_asked(): void
    {
        User::factory()->create();
        Track::factory()->count(3)->create();
        Track::factory()->audiobook()->count(3)->create();

        $this->artisan('app:playlist', ['name' => 'Musik', '--tracks' => 10])->assertExitCode(0);
        $this->artisan('app:playlist', ['name' => 'Hörbuch', '--tracks' => 10, '--type' => 'audiobook'])
            ->assertExitCode(0);

        $this->assertSame(3, Playlist::query()->where('name', 'Musik')->firstOrFail()->playlistTracks()->count());
        $this->assertSame(3, Playlist::query()->where('name', 'Hörbuch')->firstOrFail()->playlistTracks()->count());
    }

    public function test_any_mixes_music_and_audiobook_chapters(): void
    {
        // A playlist is allowed to hold both — that is what the unified `tracks` table is for.
        User::factory()->create();
        Track::factory()->count(3)->create();
        Track::factory()->audiobook()->count(3)->create();

        $this->artisan('app:playlist', ['--tracks' => 10, '--type' => 'any'])->assertExitCode(0);

        $this->assertSame(6, PlaylistTrack::query()->count());
    }

    public function test_it_fills_the_named_users_playlist(): void
    {
        // Guessing would be wrong on a box shared with family and friends — see `owner()`.
        User::factory()->create(['name' => 'Ashaltiriak']);
        $other = User::factory()->create(['name' => 'Gast']);
        Track::factory()->count(3)->create();

        $this->artisan('app:playlist', ['--user' => 'Gast', '--tracks' => 2])->assertExitCode(0);

        $this->assertSame($other->id, Playlist::query()->firstOrFail()->user_id);
    }

    public function test_an_unknown_user_fails_without_writing_anything(): void
    {
        User::factory()->create(['name' => 'Ashaltiriak']);
        Track::factory()->count(3)->create();

        $this->artisan('app:playlist', ['--user' => 'Niemand'])->assertExitCode(1);

        $this->assertSame(0, Playlist::query()->count());
    }

    public function test_an_empty_library_fails_without_leaving_a_playlist_behind(): void
    {
        // Validated BEFORE the playlist is created, so a failed run leaves no half-made list.
        User::factory()->create();

        $this->artisan('app:playlist')->assertExitCode(1);

        $this->assertSame(0, Playlist::query()->count());
    }

    public function test_a_bad_type_or_count_is_rejected(): void
    {
        User::factory()->create();
        Track::factory()->count(3)->create();

        $this->artisan('app:playlist', ['--type' => 'podcast'])->assertExitCode(2);
        $this->artisan('app:playlist', ['--tracks' => 0])->assertExitCode(2);

        $this->assertSame(0, Playlist::query()->count());
    }

    public function test_filling_a_playlist_marks_it_as_changed(): void
    {
        // PlaylistTrack::$touches, which the created-one-at-a-time loop exists to keep: a bulk
        // insert would skip it and both playlist pages would claim the list was untouched.
        $user = User::factory()->create();
        Track::factory()->count(3)->create();
        $playlist = Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Testliste']);
        $before = $playlist->updated_at;

        $this->travel(1)->minute();
        $this->artisan('app:playlist', ['name' => 'Testliste', '--tracks' => 2])->assertExitCode(0);

        $this->assertTrue($playlist->fresh()->updated_at->greaterThan($before));
    }
}
