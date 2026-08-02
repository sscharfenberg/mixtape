<?php

namespace Database\Seeders;

use App\Enums\Channel;
use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use Illuminate\Database\Seeder;

/**
 * A FIXED library for the end-to-end suite. Never used by `db:seed` — the E2E run
 * calls it explicitly (see tests/e2e/support/environment.ts).
 *
 * LibrarySeeder is deliberately random (factories, `random_int`, `inRandomOrder`)
 * because a developer wants a plausible-looking library. That is the wrong property
 * for a browser test: the data is re-rolled on every run, so a spec cannot name a
 * song, and thin edge cases turn up unpredictably — a genre with one track, a page
 * with a single row — which surface as tests that fail once in twenty runs.
 *
 * So everything here is deterministic: fixed ids, fixed names, fixed durations, fixed
 * timestamps. No `fake()`, no randomness, no `now()`. Re-seeding produces byte-identical
 * rows, which is what lets a spec assert "searching Paranoid returns exactly one row".
 *
 * The fixture is also SHAPED for the tests, and each choice below is load-bearing:
 *
 * - 67 music tracks, comfortably past DataTableService's default page size of 50, so a
 *   listing really pages and "go to page 2" is not a no-op.
 * - One artist is a long COLLABORATION credit rather than a band name. Those are real —
 *   this collection's longest runs to 106 characters, four performers and their
 *   instruments — and they are what the genre page's artist cards have to wrap rather
 *   than truncate, so the fixture carries one to guard that.
 * - Durations are unique, so ordering by them is total — with ties, two correct
 *   orderings exist and an equality assertion picks one arbitrarily.
 * - Exactly one track carries no duration, composer or publisher: the untagged rip
 *   that proves a missing field DISAPPEARS rather than rendering "0:00" or "null".
 * - "Sigur Rós" exists so accent-folded search is genuinely exercised — searching
 *   "Ros" has to find "Rós", which is the whole point of the `name_fold` columns.
 * - Every genre holds at least two tracks, so a genre page can always be sorted.
 * - Some tracks claim embedded cover art and some do not. The claim is a lie in both
 *   cases (no file exists), which is deliberate: it exercises CoverImage's 404
 *   fallback, the one case that needs a real browser to verify.
 */
class E2ESeeder extends Seeder
{
    /**
     * Album title, year, artist key, genre key, bit rate, and the real track listing.
     *
     * Real track titles rather than generated ones, because the specs read better and
     * a search term taken from them ("Paranoid") is unambiguous in a way that
     * "Track 07" never is.
     *
     * @var array<string, array{year: int, artist: string, genre: string, bitRate: int, tracks: list<string>}>
     */
    private const ALBUMS = [
        'OK Computer' => [
            'year' => 1997,
            'artist' => 'Radiohead',
            'genre' => 'Alternative Rock',
            'bitRate' => 320000,
            'tracks' => [
                'Airbag', 'Paranoid Android', 'Subterranean Homesick Alien',
                'Exit Music (For a Film)', 'Let Down', 'Karma Police', 'Fitter Happier',
                'Electioneering', 'Climbing Up the Walls', 'No Surprises', 'Lucky',
                'The Tourist',
            ],
        ],
        'The Bends' => [
            'year' => 1995,
            'artist' => 'Radiohead',
            'genre' => 'Alternative Rock',
            'bitRate' => 256000,
            'tracks' => [
                'Planet Telex', 'The Bends', 'High and Dry', 'Fake Plastic Trees',
                'Bones', '(Nice Dream)', 'Just', 'My Iron Lung',
                'Bullet Proof ... I Wish I Was', 'Black Star', 'Sulk', 'Street Spirit',
            ],
        ],
        'Parklife' => [
            'year' => 1994,
            'artist' => 'Blur',
            'genre' => 'Britpop',
            'bitRate' => 192000,
            'tracks' => [
                'Girls & Boys', 'Tracy Jacks', 'End of a Century', 'Parklife',
                'Bank Holiday', 'Badhead', 'The Debt Collector', 'Far Out',
                'To the End', 'This Is a Low',
            ],
        ],
        'Dummy' => [
            'year' => 1994,
            'artist' => 'Portishead',
            'genre' => 'Trip Hop',
            'bitRate' => 256000,
            'tracks' => [
                'Mysterons', 'Sour Times', 'Strangers', 'It Could Be Sweet',
                'Wandering Star', "It's a Fire", 'Numb', 'Roads', 'Pedestal',
                'Biscuit', 'Glory Box',
            ],
        ],
        'The Queen Is Dead' => [
            'year' => 1986,
            'artist' => 'The Smiths',
            'genre' => 'Britpop',
            'bitRate' => 192000,
            'tracks' => [
                'The Queen Is Dead', 'Frankly, Mr. Shankly', 'I Know It’s Over',
                'Never Had No One Ever', 'Cemetry Gates', 'Bigmouth Strikes Again',
                'The Boy with the Thorn in His Side', 'Vicar in a Tutu',
                'There Is a Light That Never Goes Out', 'Some Girls Are Bigger Than Others',
            ],
        ],
        // The long-credit case: an artist whose name is a collaboration rather than a band,
        // in a genre of its own so it always has a card to assert against. Two tracks, not
        // one, to keep the "every genre holds at least two" invariant above true.
        'Sessions' => [
            'year' => 2004,
            'artist' => 'Jóhann Jóhannsson, Hildur Guðnadóttir & The Cinema Orchestra',
            'genre' => 'Modern Classical',
            'bitRate' => 256000,
            'tracks' => ['Theme', 'Reprise'],
        ],
        'Ágætis byrjun' => [
            'year' => 1999,
            'artist' => 'Sigur Rós',
            'genre' => 'Post-Rock',
            'bitRate' => 320000,
            'tracks' => [
                'Intro', 'Svefn-g-englar', 'Starálfur', 'Flugufrelsarinn', 'Ný batterí',
                'Hjartað hamast', 'Viðrar vel til loftárása', 'Olsen Olsen',
                'Ágætis byrjun', 'Avalon',
            ],
        ],
    ];

    /**
     * Build the fixture.
     *
     * Order matters only in that genres and artists must exist before the albums that
     * reference them; within each table rows are created in a fixed order so that any
     * listing sorted by insertion is stable too.
     */
    public function run(): void
    {
        // One definition of the seeded account, shared with normal dev seeding.
        $this->call(UserSeeder::class);

        $genres = $this->seedGenres();
        $artists = $this->seedArtists();

        $trackNumber = 0;
        $albumNumber = 0;
        foreach (self::ALBUMS as $title => $album) {
            $albumNumber++;
            $collection = Collection::query()->create([
                'id' => $this->id(3, $albumNumber),
                'type' => CollectionType::Album,
                'name' => $title,
                'year' => $album['year'],
                'album_artist_id' => $artists[$album['artist']],
                'author_id' => null,
                // Null on purpose: no cover file exists, so the album page must draw its
                // placeholder rather than request an image that 404s.
                'cover_path' => null,
            ]);

            foreach ($album['tracks'] as $index => $name) {
                $trackNumber++;
                $this->seedTrack($collection, $artists[$album['artist']], $genres[$album['genre']], $name, $index + 1, $trackNumber, $album['bitRate']);
            }
        }
    }

    /**
     * Create the genres, keyed by name so the album loop can look one up.
     *
     * @return array<string, string> genre name => id
     */
    private function seedGenres(): array
    {
        $ids = [];
        foreach (['Alternative Rock', 'Britpop', 'Modern Classical', 'Post-Rock', 'Trip Hop'] as $index => $name) {
            $ids[$name] = Genre::query()->create([
                'id' => $this->id(1, $index + 1),
                'name' => $name,
            ])->id;
        }

        return $ids;
    }

    /**
     * Create the artists, keyed by name.
     *
     * "Sigur Rós" is in the list specifically so accent folding has something to fold —
     * a search for "Ros" must return it.
     *
     * @return array<string, string> artist name => id
     */
    private function seedArtists(): array
    {
        $ids = [];
        foreach ([
            'Blur',
            'Jóhann Jóhannsson, Hildur Guðnadóttir & The Cinema Orchestra',
            'Portishead',
            'Radiohead',
            'Sigur Rós',
            'The Smiths',
        ] as $index => $name) {
            $ids[$name] = Artist::query()->create([
                'id' => $this->id(2, $index + 1),
                'name' => $name,
            ])->id;
        }

        return $ids;
    }

    /**
     * Create one track with values derived from its position, so the whole library is a
     * pure function of this file.
     *
     * `$position` is the running index across ALL albums, which is what keeps durations,
     * sizes and paths unique without a random source. The single untagged track is the
     * seventh: far enough in to sit on the first page of a 25-row listing, so a spec can
     * see it without paging.
     */
    private function seedTrack(Collection $collection, string $artistId, string $genreId, string $name, int $trackNo, int $position, int $bitRate): void
    {
        $untagged = $position === 7;
        // Unique, spread across a believable range, and never tied — a tie would make
        // "ordered by duration" have two correct answers.
        $duration = $untagged ? null : 121.0 + ($position * 7);

        Track::query()->create([
            'id' => $this->id(4, $position),
            'type' => TrackType::Music,
            'collection_id' => $collection->id,
            'artist_id' => $artistId,
            'genre_id' => $genreId,
            'narrator_id' => null,
            'composer' => $untagged ? null : 'Test Composer',
            'publisher' => $untagged ? null : 'Test Records',
            'name' => $name,
            'path' => sprintf('/music/%03d.mp3', $position),
            'content_hash' => hash('sha256', 'mixtape-e2e-'.$position),
            'size' => $duration === null ? null : (int) ($duration * ($bitRate / 8)),
            // Fixed instants, not now(): a rendered date must not change between runs.
            'modified_at' => '2026-07-28 14:23:05',
            'created_at' => '2026-07-21 09:00:00',
            'codec' => 'mp3',
            'channel' => Channel::Stereo,
            'duration' => $duration,
            'sample_rate' => 44100,
            'bit_rate' => $untagged ? null : $bitRate,
            'vbr' => false,
            // Alternating, and a lie either way — no file exists — which is exactly what
            // exercises CoverImage's fallback from a 404 to its placeholder.
            'cover' => $position % 2 === 0,
            'track' => $trackNo,
            'disc' => 1,
        ]);
    }

    /**
     * A stable UUID from a table group and a row number.
     *
     * Explicit ids rather than the model's generated uuid7, so a failing run can be
     * inspected against a known id and two runs are genuinely identical. The shape is a
     * valid v7-looking UUID; only its stability matters.
     */
    private function id(int $group, int $row): string
    {
        return sprintf('019e%04d-0000-7000-8000-%012d', $group, $row);
    }
}
