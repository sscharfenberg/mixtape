<?php

namespace Tests\Feature\Playlists;

use App\Models\Artist;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /playlists/{playlist}/export` — the playlist as a downloadable .m3u.
 *
 * The file's BYTES are what this pins, because everything about the feature is invisible
 * otherwise: nothing on screen changes, and a mangled encoding or a doubled slash only shows
 * up on whatever the reader plays it on, hours later and somewhere else.
 *
 * Most of these guard one of the six decisions PlaylistExport's docblock lists, and each test
 * names which. The rest cover the disclosure rule this whole area follows, and the validation.
 */
class ExportPlaylistTest extends TestCase
{
    use RefreshDatabase;

    /** A reader with a playlist of `$tracks`, in the order given. @param array<int, Track> $tracks */
    private function playlistOf(array $tracks, ?User &$reader = null, string $name = 'Roadtrip'): Playlist
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id, 'name' => $name]);

        foreach ($tracks as $position => $track) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'track_id' => $track->id,
                'position' => $position,
            ]);
        }

        return $playlist;
    }

    /** One music track with the three columns a line is built from. */
    private function track(string $path, string $name = 'Airbag', ?string $artist = 'Radiohead', ?float $duration = 284.7): Track
    {
        return Track::factory()->create([
            'path' => $path,
            'name' => $name,
            'duration' => $duration,
            // firstOrCreate: `artists.name` is unique, and several of these tracks share one.
            'artist_id' => $artist === null ? null : Artist::firstOrCreate(['name' => $artist])->id,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $playlist = Playlist::factory()->create();

        $this->get("/playlists/{$playlist->id}/export")->assertRedirect('/login');
    }

    public function test_a_strangers_playlist_answers_404_rather_than_403(): void
    {
        // The disclosure rule the whole area follows: 403 would confirm the playlist exists.
        $playlist = $this->playlistOf([$this->track('a.mp3')]);

        $this->actingAs(User::factory()->create())
            ->get("/playlists/{$playlist->id}/export")
            ->assertNotFound();
    }

    public function test_a_simple_m3u_is_one_path_per_line(): void
    {
        $playlist = $this->playlistOf([
            $this->track('Radiohead/OK Computer/01 Airbag.mp3'),
            $this->track('Blur/Parklife/01 Girls & Boys.mp3', 'Girls & Boys', 'Blur'),
        ], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?prefix=")
            ->assertOk()
            ->getContent();

        // CRLF, deliberately: some Windows-era players do not read a bare LF.
        $this->assertSame(
            "Radiohead/OK Computer/01 Airbag.mp3\r\nBlur/Parklife/01 Girls & Boys.mp3\r\n",
            $body
        );
    }

    public function test_an_extended_m3u_carries_the_header_and_a_runtime_per_track(): void
    {
        $playlist = $this->playlistOf([$this->track('a.mp3', 'Airbag', 'Radiohead', 284.7)], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?format=extended&prefix=")
            ->getContent();

        // Whole seconds, floored — 284.7 is 284, not 285 and not "284.7".
        $this->assertSame("#EXTM3U\r\n#EXTINF:284,Radiohead - Airbag\r\na.mp3\r\n", $body);
    }

    public function test_an_unknown_runtime_is_minus_one_rather_than_zero(): void
    {
        // Legacy wrote `floor(null)` = 0, which players read as a zero-length track; -1 is the
        // value the convention reserves for "unknown" (PlaylistExport, change 5).
        $playlist = $this->playlistOf([$this->track('a.mp3', 'Fitter Happier', 'Radiohead', null)], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?format=extended&prefix=")
            ->getContent();

        $this->assertStringContainsString('#EXTINF:-1,Radiohead - Fitter Happier', $body);
    }

    public function test_a_track_crediting_nobody_is_its_title_alone(): void
    {
        // Not " - Title", which is what concatenating a null artist produces.
        $playlist = $this->playlistOf([$this->track('a.mp3', 'Untitled', null)], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?format=extended&prefix=")
            ->getContent();

        $this->assertStringContainsString('#EXTINF:284,Untitled', $body);
        $this->assertStringNotContainsString(' - Untitled', $body);
    }

    public function test_the_prefix_is_joined_with_exactly_one_slash(): void
    {
        /*
         * Legacy concatenated the two, so anyone who typed the trailing slash got `//` in every
         * path. Stored paths carry no leading slash, so the separator has to be added — and
         * added once whichever way the reader typed it.
         */
        $playlist = $this->playlistOf([$this->track('Radiohead/a.mp3')], $reader);

        foreach (['/Volumes/media/music', '/Volumes/media/music/'] as $prefix) {
            $body = $this->actingAs($reader)
                ->get("/playlists/{$playlist->id}/export?prefix=".urlencode($prefix))
                ->getContent();

            $this->assertSame("/Volumes/media/music/Radiohead/a.mp3\r\n", $body);
        }
    }

    public function test_an_empty_prefix_yields_the_bare_relative_path(): void
    {
        // What a player wants when the file sits beside the playlist.
        $playlist = $this->playlistOf([$this->track('Radiohead/a.mp3')], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?prefix=")
            ->getContent();

        $this->assertSame("Radiohead/a.mp3\r\n", $body);
    }

    public function test_the_prefix_defaults_to_config_when_none_is_sent(): void
    {
        config(['mixtape.playlists.export.path_prefix' => '/mnt/music']);
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader);

        $body = $this->actingAs($reader)->get("/playlists/{$playlist->id}/export")->getContent();

        $this->assertSame("/mnt/music/a.mp3\r\n", $body);
    }

    public function test_windows_1252_really_converts_and_marks_what_it_cannot_carry(): void
    {
        /*
         * The encoding choice exists for one real device (a VW ID-7), so it has to actually
         * change the bytes. "ó" is representable in Windows-1252 and must survive as its single
         * byte; "日" is not, and becomes a VISIBLE "?" rather than vanishing — mbstring's
         * default is to drop it silently, which shortens a title with no trace
         * (PlaylistExport, change 3).
         */
        $playlist = $this->playlistOf([$this->track('Sigur Rós/日.mp3')], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?encoding=".urlencode('Windows-1252').'&prefix=')
            ->getContent();

        $this->assertSame("Sigur R\xF3s/?.mp3\r\n", $body);
    }

    public function test_utf8_is_sent_through_untouched(): void
    {
        $playlist = $this->playlistOf([$this->track('Sigur Rós/日.mp3')], $reader);

        $body = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?prefix=")
            ->getContent();

        $this->assertSame("Sigur Rós/日.mp3\r\n", $body);
    }

    public function test_it_exports_in_the_readers_own_order(): void
    {
        // A playlist IS its running order, so the file has to be in it rather than in whatever
        // order the rows were inserted.
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id]);
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id, 'position' => 1,
            'track_id' => $this->track('second.mp3')->id,
        ]);
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id, 'position' => 0,
            'track_id' => $this->track('first.mp3')->id,
        ]);

        $body = $this->actingAs($reader)->get("/playlists/{$playlist->id}/export?prefix=")->getContent();

        $this->assertSame("first.mp3\r\nsecond.mp3\r\n", $body);
    }

    public function test_it_answers_as_a_download_with_the_right_type(): void
    {
        // Legacy sent `application/vnd`, which is not a media type at all.
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader, 'Roadtrip');

        $response = $this->actingAs($reader)->get("/playlists/{$playlist->id}/export");

        $response->assertHeader('Content-Type', 'audio/x-mpegurl; charset=UTF-8');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Roadtrip.m3u', $response->headers->get('Content-Disposition'));
    }

    public function test_a_hostile_playlist_name_cannot_escape_the_filename(): void
    {
        /*
         * A name is free text. Legacy interpolated it raw, so a slash made it a path and a quote
         * or a newline broke the header it sits in. The `filename*` parameter carries the real
         * characters percent-encoded; what must never appear is a raw quote, slash or CR/LF.
         */
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader, '../../etc/"pass wd"');

        $disposition = $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export")
            ->headers->get('Content-Disposition');

        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringNotContainsString('"pass', $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    public function test_a_name_of_nothing_usable_still_produces_a_named_file(): void
    {
        // Rather than a file called ".m3u", which is hidden on every unix-like system.
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader, '///');

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export")
            ->assertHeader('Content-Disposition', 'attachment; filename=playlist.m3u');
    }

    public function test_an_unknown_format_or_encoding_is_rejected(): void
    {
        /*
         * Legacy passed both straight through — `encoding` reached `mb_convert_encoding` as
         * whatever the caller sent. A 302-with-errors rather than a mangled file.
         */
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?format=csv")
            ->assertSessionHasErrors('format');

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?encoding=EBCDIC")
            ->assertSessionHasErrors('encoding');
    }

    public function test_a_prefix_carrying_a_line_break_is_rejected(): void
    {
        // The one character that turns a prefix into forged content: it would split the .m3u
        // into extra lines the reader never asked for.
        $playlist = $this->playlistOf([$this->track('a.mp3')], $reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export?prefix=".urlencode("/mnt\n#EXTM3U"))
            ->assertSessionHasErrors('prefix');
    }

    public function test_an_empty_playlist_exports_an_empty_file(): void
    {
        // Not an error: a playlist with nothing in it is a legitimate thing to export, and an
        // empty .m3u is what it is.
        $playlist = $this->playlistOf([], $reader);

        $this->actingAs($reader)
            ->get("/playlists/{$playlist->id}/export")
            ->assertOk();

        $this->assertSame('', $this->actingAs($reader)->get("/playlists/{$playlist->id}/export")->getContent());
    }
}
