<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /playlists/export` — every playlist the reader keeps, as one .zip.
 *
 * WHAT IS ACTUALLY TESTED IS THE ARCHIVE'S CONTENTS, unpacked. A zip that streams without
 * erroring proves nothing: the failure this feature invites is a download that arrives, opens,
 * and is missing a playlist — because two names collided into one entry, or because a scope was
 * forgotten and it holds somebody else's. Neither shows up in a status code.
 *
 * The .m3u bytes themselves are ExportPlaylistTest's, and the zip format is ZipStreamTest's;
 * what is left here is the set of entries, the scoping, and the options reaching every one of
 * them alike.
 */
class ExportAllPlaylistsTest extends TestCase
{
    use RefreshDatabase;

    /** A playlist of one track, at a known position in the reader's own order. */
    private function playlistFor(User $reader, string $name, string $path, int $position = 0): Playlist
    {
        $playlist = Playlist::factory()->create([
            'user_id' => $reader->id,
            'name' => $name,
            'position' => $position,
        ]);

        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => Track::factory()->create(['path' => $path])->id,
            'position' => 0,
        ]);

        return $playlist;
    }

    /**
     * The archive's entries, as `name => contents`.
     *
     * Written to a temp file and read with PHP's own zip extension rather than parsed by hand:
     * the point of the assertion is that a REAL reader can open what this sends, and a bespoke
     * parser would only prove the bytes match the writer that produced them.
     *
     * @return array<string, string>
     */
    private function unzip(string $body): array
    {
        $file = tempnam(sys_get_temp_dir(), 'mixtape-zip');
        file_put_contents($file, $body);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'the response is not a readable zip');

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($file);

        return $entries;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/playlists/export')->assertRedirect('/login');
    }

    public function test_the_route_is_not_read_as_a_playlist_id(): void
    {
        // It sits beside `/playlists/{playlist}`, which is UUID-constrained precisely so this
        // literal keeps matching its own route.
        $this->assertSame('/playlists/export', route('playlists.export.all', absolute: false));
    }

    public function test_it_holds_one_m3u_per_playlist(): void
    {
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Roadtrip', 'a.mp3', 0);
        $this->playlistFor($reader, 'Sunday', 'b.mp3', 1);

        $entries = $this->unzip(
            $this->actingAs($reader)->get('/playlists/export?prefix=')->assertOk()->streamedContent()
        );

        $this->assertSame(['Roadtrip.m3u', 'Sunday.m3u'], array_keys($entries));
        $this->assertSame("a.mp3\r\n", $entries['Roadtrip.m3u']);
        $this->assertSame("b.mp3\r\n", $entries['Sunday.m3u']);
    }

    public function test_it_never_holds_another_readers_playlist(): void
    {
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Mine', 'mine.mp3');
        $this->playlistFor(User::factory()->create(), 'Theirs', 'theirs.mp3');

        $entries = $this->unzip(
            $this->actingAs($reader)->get('/playlists/export?prefix=')->streamedContent()
        );

        $this->assertSame(['Mine.m3u'], array_keys($entries));
    }

    public function test_two_playlists_whose_names_sanitise_alike_both_survive(): void
    {
        /*
         * The failure this exists for is silent: entry names are the keys ZipStream
         * de-duplicates on, and `PlaylistExport::filename` replaces every run of characters a
         * filesystem will not take with one "_" — so "Rock/Pop" and "Rock?Pop" arrive at the
         * same name, and the reader would receive one file where they asked for two, with
         * nothing to say which was dropped. (A SPACE survives that pass, which is why the
         * obvious pair to reach for — "Rock/Pop" and "Rock Pop" — does not collide at all.)
         */
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Rock/Pop', 'a.mp3', 0);
        $this->playlistFor($reader, 'Rock?Pop', 'b.mp3', 1);

        $entries = $this->unzip(
            $this->actingAs($reader)->get('/playlists/export?prefix=')->streamedContent()
        );

        $this->assertSame(['Rock_Pop.m3u', 'Rock_Pop (2).m3u'], array_keys($entries));
        $this->assertSame("a.mp3\r\n", $entries['Rock_Pop.m3u']);
        $this->assertSame("b.mp3\r\n", $entries['Rock_Pop (2).m3u']);
    }

    public function test_two_playlists_differing_only_in_case_both_survive_extraction(): void
    {
        /*
         * A second collision hiding behind the first, and it does NOT show up in the archive:
         * `playlists.name` is unique per owner under a deterministic collation, so "Rock" and
         * "rock" are two playlists somebody can really keep, and they produce two valid, distinct
         * zip entries. The loss lands at the far end — macOS and Windows unpack onto
         * case-insensitive filesystems, where the second file overwrites the first.
         *
         * So the assertion is about the NAMES rather than about the count: two entries that
         * differ only in case would pass a `assertCount(2)` and still lose a playlist on the way
         * out of the archive.
         */
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Rock', 'a.mp3', 0);
        $this->playlistFor($reader, 'rock', 'b.mp3', 1);

        $entries = $this->unzip(
            $this->actingAs($reader)->get('/playlists/export?prefix=')->streamedContent()
        );

        $folded = array_map('mb_strtolower', array_keys($entries));

        $this->assertCount(2, $entries);
        $this->assertSame($folded, array_unique($folded), 'two entries would overwrite each other when unpacked');
    }

    public function test_an_empty_playlist_still_gets_a_file(): void
    {
        // A truthful export of a playlist holding nothing. An absent entry would read as one
        // that failed to export.
        $reader = User::factory()->create();
        Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Empty']);

        $entries = $this->unzip(
            $this->actingAs($reader)->get('/playlists/export?prefix=')->streamedContent()
        );

        $this->assertSame(['Empty.m3u'], array_keys($entries));
        $this->assertSame('', $entries['Empty.m3u']);
    }

    public function test_the_options_reach_every_playlist_alike(): void
    {
        // The whole reason one dialog covers the set: the reader is describing one device.
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'One', 'a.mp3', 0);
        $this->playlistFor($reader, 'Two', 'b.mp3', 1);

        $entries = $this->unzip(
            $this->actingAs($reader)
                ->get('/playlists/export?format=extended&prefix=/Volumes/media/music')
                ->streamedContent()
        );

        foreach ($entries as $body) {
            $this->assertStringStartsWith("#EXTM3U\r\n", $body);
            $this->assertStringContainsString('/Volumes/media/music/', $body);
        }
    }

    public function test_it_carries_the_headers_a_download_needs(): void
    {
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Roadtrip', 'a.mp3');

        $response = $this->actingAs($reader)->get('/playlists/export?prefix=')->assertOk();
        $body = $response->streamedContent();

        $response->assertHeader('Content-Type', 'application/zip');
        // Exact, because nothing is compressed — which is what gives the browser a progress bar.
        $response->assertHeader('Content-Length', (string) strlen($body));
        $this->assertStringContainsString('playlists.zip', $response->headers->get('Content-Disposition'));
    }

    public function test_a_reader_with_no_playlists_gets_a_404_rather_than_an_empty_zip(): void
    {
        // The button is not drawn for them, so this is a hand-written URL — and an archive of
        // nothing is not an answer to it. The same rule the album download follows.
        $this->actingAs(User::factory()->create())
            ->get('/playlists/export')
            ->assertNotFound();
    }

    public function test_an_option_outside_its_list_is_refused(): void
    {
        $reader = User::factory()->create();
        $this->playlistFor($reader, 'Roadtrip', 'a.mp3');

        $this->actingAs($reader)
            ->get('/playlists/export?encoding=UTF-16')
            ->assertSessionHasErrors('encoding');
    }
}
