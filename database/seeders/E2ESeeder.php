<?php

namespace Database\Seeders;

use App\Enums\Channel;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
 * - A populated PLAYLIST and an empty one, per account that reads them. Nothing in the UI
 *   adds a track to a playlist yet, so this is the only way either page gets a populated
 *   one — see seedPlaylists() and the PLAYLIST_TRACKS constant.
 */
class E2ESeeder extends Seeder
{
    /**
     * The share link the guest spec opens — an album, still live.
     *
     * A CONSTANT RATHER THAN A GENERATED ID, and spelled out in full rather than built by
     * {@see id()}, because it is read from a Playwright spec that cannot call PHP: the URL
     * has to be a literal on both sides (tests/e2e/guest/share.spec.ts).
     */
    public const LIVE_SHARE = '019e0007-0000-7000-8000-000000000001';

    /** …and one whose week is up, so the page that says so is reachable too. */
    public const EXPIRED_SHARE = '019e0007-0000-7000-8000-000000000002';

    /**
     * A SECOND dead link, for the app spec that re-activates one.
     *
     * IT EXISTS SO THAT SPEC DOES NOT TOUCH {@see EXPIRED_SHARE}, which the guest spec opens to
     * see the "this link has expired" page: renewing that one would break a spec in another
     * project from a file that never mentions it — the exact cross-spec failure an account per
     * spec exists to prevent, arriving by way of a fixture instead. An ALBUM, so the row is
     * findable in the list by a name no other seeded share carries.
     */
    public const RENEWABLE_SHARE = '019e0007-0000-7000-8000-000000000003';

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
    /**
     * An account per E2E spec file that leaves USER-SCOPED STATE behind — a play queue for
     * most of them, playlists for the last two.
     *
     * THE PLAY QUEUE IS SERVER STATE NOW (`player_states`, synced by usePlayerQueue), and
     * that broke an assumption the suite had always been able to make: that a fresh browser
     * context is a fresh player. It is not — a queue follows the USER, so with one shared
     * account a spec in one worker restores the queue another worker just left, and the
     * failure surfaces two files away from its cause.
     *
     * One account per file that touches the queue is the cheapest fix that survives
     * `fullyParallel`: Playwright never splits a FILE across workers, so a file's tests run
     * sequentially against an account nothing else can reach. What they still owe each other
     * is a reset between tests, which is `clearServerQueue` in the E2E support.
     *
     * PLAYLISTS NEEDED THE SAME TREATMENT for a different reason, and `spec-add-to-playlist`
     * is the case that showed it: that spec creates playlists, and rows added to the shared
     * account's listing moved the coordinates `playlists.spec.ts`'s DRAG test computes from
     * its own rows — a failure in a file the new one never touches. Anything user-scoped and
     * written by a spec belongs to an account of its own, queue or not.
     *
     * AND `spec-playlists` IS HERE FOR THE SESSION RATHER THAN THE ROWS (2026-08-12) — a third
     * reason, and the least obvious of them: Inertia carries validation errors and flash
     * messages in the session, Laravel writes the session whole, and two workers sharing one
     * cookie lose one of the two writes. See SPEC_USERS in tests/e2e/support/environment.ts,
     * where the measurements are.
     *
     * The names are the spec files they serve, so a stray row in the database says which
     * spec left it. Everything else keeps signing in as the canonical seeded account.
     */
    private function seedSpecUsers(): void
    {
        $names = ['spec-queue', 'spec-player', 'spec-now-playing', 'spec-shortcuts', 'spec-widgets', 'spec-playlist-detail', 'spec-add-to-playlist', 'spec-playlists'];

        foreach ($names as $name) {
            User::factory()->create([
                'name' => $name,
                'email' => $name.'@mixtape.test',
                'email_verified_at' => now(),
                'password' => Hash::make('passwort'),
            ]);
        }
    }

    public function run(): void
    {
        // One definition of the seeded account, shared with normal dev seeding.
        $this->call(UserSeeder::class);
        $this->seedSpecUsers();

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

        // Last, because both pick rows out of the library above by name.
        $this->seedPlaylists();
        $this->seedShares();
    }

    /**
     * Two share links with FIXED ids, so a signed-out browser has something to open.
     *
     * THE GUEST SPEC CANNOT MINT ITS OWN. Minting is behind `auth`, and the guest project
     * runs with no stored session at all (by directory, so a stray storageState cannot make
     * an auth test pass by accident) — so the only way a spec with no account can visit
     * `/s/{share}` is for the fixture to have handed it a link, exactly as a friend would.
     *
     * A LIVE ONE AND A DEAD ONE, because the two answer differently and both branches are
     * reachable only through a real link: the live one plays, the expired one renders the
     * page that says so. A REVOKED link needs no fixture — it is a row that does not exist,
     * which any unused UUID already is.
     *
     * An ALBUM for the live one, deliberately: it is the only subject kind whose page draws
     * the track list, so it exercises the whole page rather than just the hero. The expired
     * one is a song, which keeps the two rows describing different code.
     *
     * A THIRD ROW SINCE 2026-08-13 ({@see RENEWABLE_SHARE}): dead as well, and owned by the same
     * account, so the app spec can re-activate one without disturbing the dead link the GUEST
     * spec reads. Same reasoning as the note on the constant.
     *
     * Owned by the canonical account rather than a spec user of its own: nothing about a
     * share is user-scoped from the guest's side, and the reader never sees who minted it
     * (SharePageController says why that is deliberate rather than an omission).
     */
    private function seedShares(): void
    {
        $userId = User::query()->where('name', 'Ashaltiriak')->value('id');

        Share::query()->create([
            'id' => self::LIVE_SHARE,
            'user_id' => $userId,
            'collection_id' => Collection::query()->where('name', 'OK Computer')->value('id'),
            // A fixed instant would be in the past the moment it arrives, and `now()` is
            // exactly the non-determinism this seeder avoids everywhere else — but an expiry
            // is a fact about the clock, and the only stable thing to say is "well ahead of
            // it". Far enough out that the date it renders never depends on when the run
            // happened to start.
            'valid_until' => now()->addYears(5),
        ]);

        Share::query()->create([
            'id' => self::EXPIRED_SHARE,
            'user_id' => $userId,
            'track_id' => Track::query()->where('name', 'Paranoid Android')->value('id'),
            'valid_until' => now()->subDays(3),
        ]);

        // Well inside the thirty-day grace period, because that is the state the re-activate
        // button exists for: a link that died last week and can be switched back on.
        Share::query()->create([
            'id' => self::RENEWABLE_SHARE,
            'user_id' => $userId,
            'collection_id' => Collection::query()->where('name', 'The Bends')->value('id'),
            'valid_until' => now()->subDays(4),
        ]);
    }

    /**
     * The playlists the fixture ships with — one populated, one empty, per account that
     * needs them.
     *
     * WHY THE FIXTURE CARRIES THEM AT ALL: they were written when nothing in the UI could add
     * a track to a playlist, so a populated one could not be built by driving the app. Without
     * these, a spec would have to INSERT its own (which playlist-detail.spec.ts did until this
     * landed), and a human running the E2E app to look at something would find a playlists area
     * with nothing in it. The UI caught up on 2026-08-09, but building a twelve-entry fixture
     * through the browser would cost every spec that reads it a dozen round trips to arrive at
     * rows a seeder writes in one statement.
     *
     * BOTH accounts get the populated one because playlists are PRIVATE PER OWNER: the
     * canonical reader's copy is what a person browsing the seeded app sees, and
     * `spec-playlist-detail` needs its own or the page it reads would 404. That account owns
     * its playlists for the reason SPEC_USERS exists — its spec presses play, and a play queue
     * follows the user across workers.
     *
     * The EMPTY one exists because that is the state a new account meets first, and the page
     * has a whole branch for it.
     *
     * The THIRD, for the spec account only, is there because reordering WRITES: a drag spec
     * renumbers the playlist it drags, and a fixture shared with the tests that assert the
     * reader's own order would leave those passing or failing on which ran first. It holds the
     * same entries — what it is for is being disturbed.
     */
    private function seedPlaylists(): void
    {
        $tracks = Track::query()->whereIn('name', self::PLAYLIST_TRACKS)->pluck('id', 'name');
        // In the constant's order, which is the point of it — see PLAYLIST_TRACKS.
        $entries = array_map(fn (string $name): string => $tracks[$name], self::PLAYLIST_TRACKS);

        $row = 0;
        foreach (['Ashaltiriak', 'spec-playlist-detail'] as $owner) {
            $userId = User::query()->where('name', $owner)->value('id');

            $this->seedPlaylist(
                $userId,
                ++$row,
                'Roadtrip',
                $entries,
                // Changed after it was made, so the hero's and the row's "Geändert" tile has
                // something to print. Fixed instants, not now(): a rendered date must not
                // change between runs.
                '2026-07-22 10:15:00',
                '2026-07-30 18:42:00',
            );

            $this->seedPlaylist($userId, ++$row, 'Ganz frisch', [], '2026-07-31 08:05:00', '2026-07-31 08:05:00');

            // The one the drag specs are allowed to renumber — see the note above.
            if ($owner === 'spec-playlist-detail') {
                $this->seedPlaylist(
                    $userId,
                    ++$row,
                    'Umsortieren',
                    $entries,
                    '2026-07-22 10:15:00',
                    '2026-07-30 18:42:00',
                );
            }
        }
    }

    /**
     * One playlist and its entries, at fixed ids and fixed instants.
     *
     * The timestamps are written back with a QUERY update after the entries exist, and that
     * is load-bearing rather than tidy: PlaylistTrack::$touches bumps its playlist's
     * `updated_at` on every insert, with `now()` — so a playlist created the obvious way
     * carries the moment the suite happened to run, and any assertion on the date it renders
     * would be different on every run. A query update fires no model events, so it wins.
     *
     * `created_at === $changedAt` is how a playlist says "nothing has happened to me since":
     * both controllers compare the two and send null, and the page then prints no "changed"
     * tile at all.
     *
     * @param  array<int, string>  $trackIds  the entries, in the order the playlist holds them
     */
    private function seedPlaylist(string $userId, int $row, string $name, array $trackIds, string $createdAt, string $changedAt): void
    {
        $playlist = Playlist::query()->create([
            'id' => $this->id(5, $row),
            'user_id' => $userId,
            'name' => $name,
            'description' => $trackIds === [] ? null : 'Für die lange Fahrt.',
            'position' => $row,
        ]);

        foreach ($trackIds as $index => $trackId) {
            PlaylistTrack::query()->create([
                // Unique across every playlist, so two of them cannot collide on an entry id.
                'id' => $this->id(6, ($row * 100) + $index + 1),
                'playlist_id' => $playlist->id,
                'track_id' => $trackId,
                'position' => $index,
                'created_at' => $createdAt,
            ]);
        }

        Playlist::query()->whereKey($playlist->id)->update(['created_at' => $createdAt, 'updated_at' => $changedAt]);
    }

    /**
     * The seeded playlist's entries, BY NAME and in the order it holds them.
     *
     * Every choice here is a case the detail page has to handle, so the fixture exercises
     * them without a spec having to build anything:
     *
     * - THE ORDER IS NEITHER ALPHABETICAL NOR THE LIBRARY'S. A playlist IS its running order,
     *   so a page that quietly sorted by title would look perfectly fine against a list that
     *   happened to be sorted already. This one starts at K and goes back to G.
     * - Three of them ("Karma Police", "Roads", "There Is a Light…") carry cover art, and all
     *   three are off DIFFERENT albums — so the hero's fan draws its full three sleeves, which
     *   is the case that needs the per-album dedupe (a cover URL is per track, so several
     *   songs off one record would fan the same picture three times).
     * - "Fitter Happier" is the fixture's one UNTAGGED track: no duration, no bit rate. Its
     *   row must drop the clock chip rather than print "0:00".
     * - "Svefn-g-englar" carries accents and "There Is a Light That Never Goes Out" is long
     *   enough to wrap a narrow row.
     *
     * @var list<string>
     */
    private const PLAYLIST_TRACKS = [
        'Karma Police',
        'Girls & Boys',
        'Roads',
        'Fitter Happier',
        'There Is a Light That Never Goes Out',
        'Svefn-g-englar',
        'Avalon',
    ];

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
