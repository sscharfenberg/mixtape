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

        // Audiobooks: two ordinary books, each one author read by one narrator.
        foreach (Author::factory()->count(2)->create() as $author) {
            $book = Collection::factory()->audiobook()->create();
            $narrator = Narrator::factory()->create();

            foreach (range(1, random_int(8, 15)) as $chapter) {
                Track::factory()->audiobook()->create([
                    'collection_id' => $book->id,
                    'author_id' => $author->id,
                    'narrator_id' => $narrator->id,
                    'track' => $chapter,
                ]);
            }
        }

        // ...and an ANTHOLOGY, which is the shape the area has to get right: several
        // authors and several narrators inside ONE book, plus a chapter that names no
        // author at all. Taken from the real library, where "Necrophobia 1" runs four
        // authors across 33 chapters and some chapters carry no TCOM tag — the case that
        // both credits hang off the chapter.
        $anthology = Collection::factory()->audiobook()->create(['name' => 'Anthology of the Weird']);
        $contributors = Author::factory()->count(3)->create();
        $voices = Narrator::factory()->count(2)->create();

        foreach (range(1, 9) as $chapter) {
            Track::factory()->audiobook()->create([
                'collection_id' => $anthology->id,
                // The last chapter is an afterword nobody is credited with.
                'author_id' => $chapter === 9 ? null : $contributors[$chapter % 3]->id,
                'narrator_id' => $voices[$chapter % 2]->id,
                'track' => $chapter,
            ]);
        }

        // "Greatest Hits" reusing some existing audio → clones (same content_hash
        // at a different path), so the "x clones" feature has data to show.
        $sources = Track::query()->where('type', TrackType::Music)->inRandomOrder()->limit(4)->get();
        $bestOf = Collection::factory()->create([
            'name' => 'Greatest Hits',
            'album_artist_id' => $artists->first()->id,
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
