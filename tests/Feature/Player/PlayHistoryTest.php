<?php

namespace Tests\Feature\Player;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Listen history: `POST /player/plays` writing one row per listen, and the counts the song
 * page reads back out.
 *
 * WHAT COUNTS AS A LISTEN is the client's decision — heard seconds against half the track,
 * capped at four minutes — and lives in usePlayerAudio's own spec. What is worth a server
 * test is everything that decision hands over: that a row lands with the SERVER's clock on
 * it, that fifteen listens are fifteen rows rather than a counter, that a chapter is as
 * countable as a song, and that the two numbers the page shows are split the way a reader
 * would expect.
 *
 * THE COUNTS ARE BY `content_hash`, and that is the one behaviour here that would look like
 * a bug if you did not know it: the same recording sits in the library several times over —
 * album, compilation, best-of — and counting by id would report three small numbers where
 * the truth is one larger one.
 */
class PlayHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** A music track that shares one artist/album/genre, so the factory's unique pools survive. */
    private function track(array $attributes = []): Track
    {
        return Track::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'collection_id' => Collection::factory()->create()->id,
            'genre_id' => Genre::factory()->create()->id,
            ...$attributes,
        ]);
    }

    public function test_a_guest_records_no_listens(): void
    {
        // `plays.user_id` is not nullable: a listening history belongs to a person, and a
        // guest on a share link has no account to hang one on.
        $this->postJson('/player/plays', ['trackId' => $this->track()->id])->assertUnauthorized();

        $this->assertSame(0, Play::query()->count());
    }

    public function test_it_records_a_listen_with_the_servers_own_clock(): void
    {
        $user = User::factory()->create();
        $track = $this->track();

        $this->actingAs($user)
            ->postJson('/player/plays', ['trackId' => $track->id])
            ->assertNoContent();

        $play = Play::query()->sole();
        $this->assertSame($user->id, $play->user_id);
        $this->assertSame($track->id, $play->track_id);
        // The beacon fires live, so `now()` is within a round trip of the truth — and it
        // cannot be skewed by a device whose clock is wrong.
        $this->assertTrue($play->played_at->diffInSeconds(now()) < 5);
    }

    public function test_fifteen_listens_are_fifteen_rows(): void
    {
        // The counter question, settled: events are the source of truth. Fifteen rows is
        // about four kilobytes, and a counter can always be built from them — never the
        // other way round.
        $user = User::factory()->create();
        $track = $this->track();

        for ($listen = 0; $listen < 15; $listen++) {
            $this->actingAs($user)->postJson('/player/plays', ['trackId' => $track->id])->assertNoContent();
        }

        $this->assertSame(15, Play::query()->count());
    }

    public function test_an_audiobook_chapter_counts_as_much_as_a_song(): void
    {
        // `tracks` is one table for music, chapters and (future) episodes, and the beacon
        // knows nothing about the type. A guard here would silently drop every listen the
        // day audiobooks become playable — the worst of the three possible behaviours.
        $user = User::factory()->create();
        $chapter = $this->track(['type' => TrackType::Audiobook]);

        $this->actingAs($user)
            ->postJson('/player/plays', ['trackId' => $chapter->id])
            ->assertNoContent();

        $this->assertSame(1, Play::query()->count());
    }

    public function test_it_rejects_a_track_that_does_not_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/player/plays', ['trackId' => '11111111-1111-4111-8111-111111111111'])
            ->assertJsonValidationErrors('trackId');
    }

    public function test_the_song_page_splits_the_readers_listens_from_everybody_elses(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $song = $this->track();

        Play::factory()->count(3)->create(['user_id' => $reader->id, 'track_id' => $song->id]);
        Play::factory()->count(5)->create(['user_id' => $housemate->id, 'track_id' => $song->id]);

        $this->actingAs($reader)
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 3)
                ->where('plays.others', 5)
            );
    }

    public function test_a_song_nobody_has_played_reports_zeroes(): void
    {
        // Zero is what the PAGE turns into silence; the server just counts.
        $song = $this->track();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', 0)->where('plays.others', 0));
    }

    public function test_listens_to_another_copy_of_the_same_recording_count_here_too(): void
    {
        // The album track and the best-of track are one recording with one content hash.
        // Counting by id would tell a reader they had played this song twice when they had
        // played it five times.
        $reader = User::factory()->create();
        $hash = str_repeat('a', 64);
        $album = $this->track(['content_hash' => $hash]);
        $compilation = $this->track(['content_hash' => $hash]);

        Play::factory()->count(2)->create(['user_id' => $reader->id, 'track_id' => $album->id]);
        Play::factory()->count(3)->create(['user_id' => $reader->id, 'track_id' => $compilation->id]);

        $this->actingAs($reader)
            ->get("/music/songs/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', 5));
    }
}
