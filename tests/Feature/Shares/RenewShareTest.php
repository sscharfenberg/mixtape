<?php

namespace Tests\Feature\Shares;

use App\Models\Collection;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RE-ACTIVATING AN EXPIRED SHARE LINK — `PATCH /shares/{share}/renew` (docs/sharing.md →
 * "Re-activating"), the verb that makes the thirty-day grace period worth having.
 *
 * WHAT IS PINNED HERE is what the act is, and what it deliberately is not:
 *
 *   - THE SAME URL WORKS AGAIN. Not a new row and not a new address — the whole point is the link
 *     already sitting in somebody's chat window, so the test that matters is that the id which
 *     was rendering "this link has expired" now plays.
 *   - IT MOVES BETWEEN THE TWO LISTS. `/dashboard/shared` sends live and dead links as separate
 *     props, so "the row came back" is a claim about which prop holds it.
 *   - SEVEN DAYS FROM NOW, not seven added to a remainder that does not exist.
 *   - A LIVE LINK CANNOT BE RENEWED, which is the rule this endpoint would otherwise break: the
 *     mint route re-hands an existing link rather than extending it, precisely so a seven-day
 *     rule cannot be reset by pressing something every Monday.
 *   - ONLY THE MINTER MAY, and a stranger's link answers 404 rather than 403 — the same
 *     disclosure posture as revoking (RenewShareRequest).
 *
 * The sweep itself is PruneSharesTest's; what belongs here is that renewing takes a row back OUT
 * of the sweep's reach, since that is the sentence the feature is sold on.
 */
class RenewShareTest extends TestCase
{
    use RefreshDatabase;

    /** A user, an album with one track, and an EXPIRED share of it belonging to that user. */
    private function expired(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->create(['collection_id' => $album->id]);

        return [$owner, Share::factory()->ofAlbum($album)->expired()->create(['user_id' => $owner->id])];
    }

    public function test_re_activating_makes_the_link_the_reader_already_sent_work_again(): void
    {
        [$user, $share] = $this->expired();

        // It really is dead first — otherwise the assertions below prove nothing. An expired link
        // still RENDERS (it says so, and names the thing), with no tracks to play.
        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('share.expired', true)->has('tracks', 0));

        $this->actingAs($user)
            ->patch("/shares/{$share->id}/renew")
            ->assertRedirect()
            ->assertSessionHas('message');

        // The SAME url, for a caller with no session — which is the whole feature.
        $this->get("/s/{$share->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('share.expired', false)->has('tracks', 1));
    }

    public function test_it_gives_seven_days_from_now_rather_than_extending_a_remainder(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        [$user, $share] = $this->expired();

        $this->actingAs($user)->patch("/shares/{$share->id}/renew")->assertRedirect();

        // `Share::LIFETIME_DAYS` from the press, exactly as a fresh mint would get: the row was
        // finished, so there was nothing left to add to.
        $this->assertTrue(
            $share->fresh()->valid_until->equalTo(now()->addDays(Share::LIFETIME_DAYS)),
            'a renewed link should live for the app\'s standard lifetime, counted from now'
        );
    }

    public function test_the_row_moves_from_the_expired_half_of_the_list_to_the_live_one(): void
    {
        [$user, $share] = $this->expired();

        $this->actingAs($user)
            ->get('/dashboard/shared')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('shares', 0)->has('expiredShares', 1));

        $this->actingAs($user)->patch("/shares/{$share->id}/renew")->assertRedirect();

        // What the reader sees after the dialog closes: the row is back where the links that
        // work live, and the expired half is empty again.
        $this->actingAs($user)
            ->get('/dashboard/shared')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shares', 1)
                ->where('shares.0.id', $share->id)
                ->has('expiredShares', 0)
            );
    }

    public function test_a_live_link_cannot_be_renewed_because_that_would_be_an_extension(): void
    {
        [$user, $share] = $this->expired();
        $share->update(['valid_until' => now()->addDay()]);
        $before = $share->fresh()->valid_until;

        // 404 rather than 403: nothing in the app offers renewing a live link, so a caller
        // reaching here has addressed an action that is not there. The rule it protects is the
        // seven-day one — press this every Monday and a link would never die.
        $this->actingAs($user)->patch("/shares/{$share->id}/renew")->assertNotFound();

        $this->assertTrue($share->fresh()->valid_until->equalTo($before));
    }

    public function test_a_stranger_cannot_renew_a_link_and_is_not_told_it_exists(): void
    {
        [, $share] = $this->expired();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->patch("/shares/{$share->id}/renew")->assertNotFound();

        $this->assertFalse($share->fresh()->isLive());
    }

    public function test_a_guest_cannot_renew_anything(): void
    {
        [, $share] = $this->expired();

        $this->patch("/shares/{$share->id}/renew")->assertRedirect('/login');

        $this->assertFalse($share->fresh()->isLive());
    }

    public function test_a_renewed_link_is_out_of_the_sweeps_reach_again(): void
    {
        // THE SENTENCE THE FEATURE IS SOLD ON: within the grace period a dead link can be
        // brought back, and bringing it back also resets what the sweep is measuring — it prunes
        // on `valid_until`, so a renewed row's thirty days start over from its new expiry.
        [$user, $share] = $this->expired();
        $share->update(['valid_until' => now()->subDays(Share::PRUNE_AFTER_DAYS + 1)]);

        $this->actingAs($user)->patch("/shares/{$share->id}/renew")->assertRedirect();

        // The real command, as PruneSharesTest runs it: the trait and `prunable()` are what make
        // the schedule work, and calling the method directly would not notice either going away.
        $this->artisan('model:prune', ['--model' => Share::class])->assertSuccessful();

        $this->assertModelExists($share);
    }
}
