<?php

namespace Tests\Feature\Auth;

use App\Enums\TrackType;
use App\Models\Track;
use App\Models\User;
use App\Services\Auth\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Where a reader lands when they sign in.
 *
 * IT USED TO BE ONE CONFIG VALUE, and that value pointed at the ACCOUNT SETTINGS. Nobody signs
 * in to a music collection to read their own password form, so the destination is now decided
 * by what the library holds — and the four answers it can give are what this file pins, because
 * three of them are states a running instance passes through and none of them fails loudly if
 * it is wrong. A reader simply arrives somewhere odd.
 *
 * The service is exercised BOTH WAYS: directly, where the four cases are cheap, and once
 * through the real login route, because a correct answer nothing calls is worth nothing.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    /** `$count` tracks of one kind — the only fact the decision reads. */
    private function tracks(TrackType $type, int $count): void
    {
        Track::factory()->count($count)->create(['type' => $type]);
    }

    public function test_an_instance_with_no_media_lands_on_the_public_page(): void
    {
        // A fresh install, or one whose media paths are unconfigured. It is the one page that
        // says what this is rather than showing an empty table.
        $this->assertSame('/', LandingPage::path());
    }

    public function test_a_music_collection_lands_on_music(): void
    {
        $this->tracks(TrackType::Music, 3);
        $this->tracks(TrackType::Audiobook, 1);

        $this->assertSame('/music', LandingPage::path());
    }

    public function test_an_audiobook_collection_lands_on_audiobooks(): void
    {
        $this->tracks(TrackType::Music, 1);
        $this->tracks(TrackType::Audiobook, 4);

        $this->assertSame('/audiobooks', LandingPage::path());
    }

    public function test_only_one_kind_present_lands_on_that_kind(): void
    {
        // The state an instance is in while one area is still being imported.
        $this->tracks(TrackType::Audiobook, 2);

        $this->assertSame('/audiobooks', LandingPage::path());
    }

    public function test_a_tie_goes_to_music(): void
    {
        // Arbitrary, but always the same answer — which beats one that depends on the order
        // the database happened to return two rows in.
        $this->tracks(TrackType::Music, 2);
        $this->tracks(TrackType::Audiobook, 2);

        $this->assertSame('/music', LandingPage::path());
    }

    public function test_signing_in_actually_goes_there(): void
    {
        $this->tracks(TrackType::Audiobook, 2);

        $user = User::factory()->create(['name' => 'Ada', 'password' => Hash::make('s3cret-pass')]);

        $this->post('/login', ['name' => 'Ada', 'password' => 's3cret-pass'])
            ->assertRedirect('/audiobooks');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_deep_link_still_wins(): void
    {
        /*
         * The landing page is only ever the FALLBACK: both callers hand it to
         * `intended()`, so a reader bounced to the login form from a page they asked for
         * gets that page back. Losing this would be the more annoying regression of the two
         * — it turns every expired session into a trip back to the front door.
         */
        $this->tracks(TrackType::Music, 2);
        User::factory()->create(['name' => 'Ada', 'password' => Hash::make('s3cret-pass')]);

        // The bounce itself is what records the intended URL.
        $this->get('/playlists')->assertRedirect('/login');

        $this->post('/login', ['name' => 'Ada', 'password' => 's3cret-pass'])
            ->assertRedirect('/playlists');
    }
}
