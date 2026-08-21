<?php

namespace Tests\Feature\Library;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use App\Services\Library\Audit\LibraryFileIndex;
use App\Services\Library\PathEncodingAudit;
use App\Services\Library\PathEncodingAuditResult;
use App\Services\Library\PathEncodingFinding;
use App\Services\Library\PathEncodingReport;
use App\Services\Playlists\PlaylistExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Windows-1252 path audit — which files a car-stereo playlist cannot name.
 *
 * WHY THIS IS WORTH REAL TESTS. Every failure mode here is silent in both directions. Miss a
 * character and a reader exports a playlist with dead lines and finds out in the car; flag one
 * that would have been fine and they rename a file for nothing. Neither shows up on screen, and
 * neither is visible in the report — a wrong report looks exactly like a right one.
 *
 * The filesystem cases below deliberately use characters that survive any filesystem
 * unchanged. Decomposed accents are exercised at string level instead: macOS and Linux disagree
 * about whether a filename keeps the normal form it was created with, so a test that wrote one
 * to disk would be testing the volume rather than the audit.
 */
class PathEncodingAuditTest extends TestCase
{
    use InteractsWithLibraryFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    /**
     * Run the audit over the temp library.
     *
     * Through a real {@see LibraryFileIndex}, which is where the walk lives now — `app:audit`
     * builds one and hands it to every disk-side check, so a test that fabricated a path list
     * would stop covering the one place extensions and missing areas are decided.
     */
    private function audit(?array $areas = null): PathEncodingAuditResult
    {
        $areas ??= [TrackType::Music];

        return (new PathEncodingAudit)->scan(LibraryFileIndex::for($areas), $areas);
    }

    /** The relative paths flagged, so a test can assert on them without ordering noise. */
    private function flagged(PathEncodingAuditResult $result): array
    {
        $paths = array_map(fn (PathEncodingFinding $f) => $f->path, $result->findings);
        sort($paths);

        return $paths;
    }

    public function test_it_flags_a_path_the_encoding_cannot_carry(): void
    {
        $this->rawFile('Mgla/Mgła - Age of Excuse.mp3');
        $this->rawFile('Radiohead/01 - Airbag.mp3');

        $result = $this->audit();

        $this->assertSame(['Mgla/Mgła - Age of Excuse.mp3'], $this->flagged($result));
        $this->assertSame(['ł'], $result->findings[0]->offenders);
    }

    public function test_it_passes_latin_1_and_the_characters_windows_1252_adds(): void
    {
        // The block that makes a naive "is it Latin-1?" check wrong in both directions: the
        // curly quote, em dash and euro sign all survive, and would be flagged by one.
        $this->rawFile('Motörhead/Ace of Spades.mp3');
        $this->rawFile('Sigur Rós/Ágætis byrjun.mp3');
        $this->rawFile('Misc/€ – a ‘quoted’ title….mp3');

        $this->assertTrue($this->audit()->isClean());
    }

    public function test_it_reports_whole_characters_and_not_byte_halves(): void
    {
        // Walking bytes rather than code points would report meaningless fragments of these.
        $this->rawFile('Bloody Tyrant 暴君/01 - Track.mp3');

        $this->assertSame(['暴', '君'], $this->audit()->findings[0]->offenders);
    }

    public function test_it_names_each_offender_once_however_often_it_repeats(): void
    {
        $this->rawFile('Mgla/Mgła Mgła Mgła.mp3');

        $this->assertSame(['ł'], $this->audit()->findings[0]->offenders);
    }

    public function test_it_looks_only_at_configured_audio_extensions(): void
    {
        // Folder.jpg lives in almost every album directory; flagging artwork would bury the
        // tracks that actually break, and no .m3u line ever names it.
        $this->rawFile('Mgła/Folder.jpg');
        $this->rawFile('Mgła/notes.txt');

        $this->assertTrue($this->audit()->isClean());
        $this->assertSame(0, $this->audit()->scanned);
    }

    public function test_it_counts_every_file_it_examined_so_a_finding_has_a_denominator(): void
    {
        $this->rawFile('a/01.mp3');
        $this->rawFile('a/02.mp3');
        $this->rawFile('Mgła/03.mp3');

        $this->assertSame(3, $this->audit()->scanned);
    }

    public function test_it_skips_an_area_that_is_not_configured(): void
    {
        // An instance with no audiobooks should not have a reporting command that fails at it.
        config(['mixtape.library.paths.audiobooks' => '']);
        $this->rawFile('a/01.mp3');

        $result = $this->audit([TrackType::Music, TrackType::Audiobook]);

        $this->assertSame(1, $result->scanned);
        $this->assertTrue($result->isClean());
    }

    public function test_a_bad_folder_is_one_rename_and_not_one_per_file(): void
    {
        // The whole reason the report groups by segment: this is one job, not three.
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/01.mp3');
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/02.mp3');
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/03.mp3');

        $result = $this->audit();
        $targets = array_values($result->renameTargets());

        $this->assertCount(3, $result->findings);
        $this->assertCount(1, $targets);
        $this->assertSame('[1997] F♯ A♯ ∞', $targets[0]['segment']);
        $this->assertSame('Godspeed', $targets[0]['parent']);
        $this->assertTrue($targets[0]['isDirectory']);
        $this->assertSame(3, $targets[0]['files']);
    }

    public function test_it_catches_a_folder_renamed_while_its_filenames_were_left_alone(): void
    {
        /*
         * The mistake this command exists to catch, and the one actually made on this
         * collection: the album directory was cleaned up, so the path LOOKS fixed at a glance,
         * while six filenames beneath it still carried the character.
         */
        $this->rawFile('Mgla/[2019] Age of Excuse/Mgła - 01.mp3');
        $this->rawFile('Mgla/[2019] Age of Excuse/Mgła - 02.mp3');

        $targets = array_values($this->audit()->renameTargets());

        $this->assertCount(2, $targets);
        $this->assertSame(['Mgła - 01.mp3', 'Mgła - 02.mp3'], array_column($targets, 'segment'));
        $this->assertSame([false, false], array_column($targets, 'isDirectory'));
    }

    public function test_offender_counts_are_per_path_and_commonest_first(): void
    {
        $this->rawFile('Mgła/01.mp3');
        $this->rawFile('Mgła/暴 02.mp3'); // carries both
        $this->rawFile('Mgła/03.mp3');

        $this->assertSame(['ł' => 3, '暴' => 1], $this->audit()->offenderCounts());
    }

    public function test_a_decomposed_accent_is_flagged_and_marked_as_precompose_only(): void
    {
        /*
         * The subtlest case in the collection. The composed è IS in Windows-1252 and "e" plus a
         * combining grave is not, so the same word passes or fails on its normal form alone —
         * and the fix changes bytes without changing one visible glyph, which is why the finding
         * carries a flag rather than being reported like a foreign character.
         */
        $decomposed = "Kung-Fu Che\u{0300}vre.mp3";

        $this->assertSame(["\u{0300}"], PathEncodingAudit::offendersIn($decomposed));
        $this->assertSame([], PathEncodingAudit::offendersIn("Kung-Fu Ch\u{00E8}vre.mp3"));

        $report = PathEncodingReport::section(
            new PathEncodingAuditResult(1, [
                new PathEncodingFinding(TrackType::Music, 'Igorrr/'.$decomposed, ["\u{0300}"], true),
            ])
        );

        $this->assertStringContainsString('Precompose only (NFC)', $report);
        $this->assertStringContainsString('*precompose only*', $report);
    }

    public function test_the_report_spells_out_where_an_invisible_character_sits(): void
    {
        /*
         * Without this the advice cannot be followed: a private-use character is invisible in
         * Finder, so "rename this file" sends the reader hunting for something not on screen.
         */
        $report = PathEncodingReport::section(
            new PathEncodingAuditResult(1, [
                new PathEncodingFinding(TrackType::Music, "Surfers/My Room\u{F023}.mp3", ["\u{F023}"], false),
            ])
        );

        $this->assertStringContainsString('My Room⟨U+F023⟩.mp3', $report);
        $this->assertStringContainsString('no Unicode name', $report);
    }

    public function test_the_report_says_so_plainly_when_there_is_nothing_to_fix(): void
    {
        $this->rawFile('Radiohead/01 - Airbag.mp3');

        $report = PathEncodingReport::section($this->audit());

        // A clean check gets no section in the audit document at all, so what `section()` returns
        // is only ever read by a test — but it must still say something true rather than nothing.
        $this->assertStringContainsString('can be written to a Windows-1252 playlist', $report);
        $this->assertStringNotContainsString('What to rename', $report);
    }

    public function test_a_pipe_in_a_name_does_not_break_the_table_it_is_printed_in(): void
    {
        // Real album titles contain pipes, and GFM splits a table row on them before it parses
        // inline code — so a code span is no protection and an unescaped one eats the cell.
        $report = PathEncodingReport::section(
            new PathEncodingAuditResult(1, [
                new PathEncodingFinding(TrackType::Music, 'AC|DC/Mgła.mp3', ['ł'], false),
            ])
        );

        // Escaped inside the table, left alone in the prose list where a backslash would print.
        $this->assertStringContainsString('- `AC|DC/Mgła.mp3`', $report);
        $this->assertStringNotContainsString('| ł | `U+0142', $report);
    }

    public function test_it_agrees_with_what_the_exporter_actually_writes(): void
    {
        /*
         * THE BINDING TEST. The audit's promise is that it never disagrees with the exporter —
         * it asks mbstring the same question, differing only in the substitute character. Two
         * tables that drift apart would warn about the wrong files, so this pins them to each
         * other through the real render rather than through a shared constant.
         */
        $survives = 'Motörhead/Ace of Spades.mp3';
        $doesNot = 'Mgla/Mgła.mp3';

        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id]);

        foreach ([$survives, $doesNot] as $position => $path) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'position' => $position,
                'track_id' => Track::factory()->create([
                    'path' => $path,
                    'artist_id' => Artist::firstOrCreate(['name' => 'Whoever'])->id,
                ])->id,
            ]);
        }

        $m3u = PlaylistExport::render($playlist, 'simple', 'Windows-1252', '');

        // What the audit passes, the exporter writes intact (in its own encoding).
        $this->assertSame([], PathEncodingAudit::offendersIn($survives));
        $this->assertStringContainsString(mb_convert_encoding($survives, 'Windows-1252', 'UTF-8'), $m3u);

        // What the audit flags, the exporter turns into the "?" that makes the line dead.
        $this->assertSame(['ł'], PathEncodingAudit::offendersIn($doesNot));
        $this->assertStringContainsString('Mg?a.mp3', $m3u);
    }
}
