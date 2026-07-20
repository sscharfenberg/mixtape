<?php

namespace Database\Seeders;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Play;
use App\Models\PlayerState;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A realistic starter library for local development: a handful of music albums,
 * a couple of audiobooks, a "Greatest Hits" that reuses some audio (clones —
 * data-model.md → "duplicate audio is allowed"), plus listening data (a playlist,
 * plays, and a persisted queue) attached to the seeded account.
 *
 * Runs after UserSeeder so it can hang the listening data off `Ashaltiriak`.
 * Not used by the test suite (which doesn't auto-seed); this is for `db:seed` /
 * `migrate:fresh --seed`.
 */
class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        $genres = Genre::factory()->count(8)->create();
        $artists = Artist::factory()->count(8)->create();

        // Music: each artist gets 1–2 albums of 6–12 tracks.
        foreach ($artists as $artist) {
            foreach (range(1, random_int(1, 2)) as $ignored) {
                $album = Collection::factory()->create([
                    'album_artist_id' => $artist->id,
                    'author_id' => null,
                ]);

                foreach (range(1, random_int(6, 12)) as $n) {
                    Track::factory()->create([
                        'collection_id' => $album->id,
                        'artist_id' => $artist->id,
                        'genre_id' => $genres->random()->id,
                        'track' => $n,
                        'disc' => 1,
                    ]);
                }
            }
        }

        // Audiobooks: two books, each an author + narrator + chapters.
        foreach (Author::factory()->count(2)->create() as $author) {
            $book = Collection::factory()->audiobook()->create(['author_id' => $author->id]);
            $narrator = Narrator::factory()->create();

            foreach (range(1, random_int(8, 15)) as $chapter) {
                Track::factory()->audiobook()->create([
                    'collection_id' => $book->id,
                    'narrator_id' => $narrator->id,
                    'track' => $chapter,
                ]);
            }
        }

        // "Greatest Hits" reusing some existing audio → clones (same content_hash
        // at a different path), so the "x clones" feature has data to show.
        $sources = Track::query()->where('type', TrackType::Music)->inRandomOrder()->limit(4)->get();
        $bestOf = Collection::factory()->create([
            'name' => 'Greatest Hits',
            'album_artist_id' => $artists->first()->id,
            'author_id' => null,
        ]);
        foreach ($sources->values() as $i => $source) {
            Track::factory()->cloneOf($source)->create([
                'collection_id' => $bestOf->id,
                'artist_id' => $source->artist_id,
                'genre_id' => $source->genre_id,
                'track' => $i + 1,
                'path' => '/music/best-of/'.($i + 1).'.mp3',
            ]);
        }

        // Listening data for the seeded account.
        $user = User::query()->where('email', 'ashaltiriak@mixtape.me')->first()
            ?? User::query()->first();

        if (! $user) {
            return;
        }

        $picks = Track::query()->inRandomOrder()->limit(15)->get();
        $playlist = $user->playlists()->create([
            'name' => 'Mixtape #1',
            'description' => 'A seeded starter playlist.',
            'position' => 0,
        ]);
        foreach ($picks->values() as $position => $track) {
            $playlist->playlistTracks()->create(['track_id' => $track->id, 'position' => $position]);
        }

        Track::query()->inRandomOrder()->limit(40)->get()->each(
            fn (Track $track) => Play::factory()->create([
                'user_id' => $user->id,
                'track_id' => $track->id,
            ])
        );

        PlayerState::factory()->create([
            'user_id' => $user->id,
            'queue' => [
                'items' => $picks->take(5)->pluck('id')->all(),
                'current_index' => 0,
                'position_ms' => 0,
            ],
        ]);
    }
}
