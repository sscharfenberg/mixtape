<?php

namespace Tests\Feature\Shares;

use App\Models\Collection;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SWEEPING DEAD SHARE LINKS — `php artisan model:prune --model="App\Models\Share"`, run weekly
 * by a systemd timer (docs/sharing.md → "Pruning").
 *
 * WHAT IS WORTH PINNING IS THE WINDOW, and both of its edges. A prune is a scheduled DELETE
 * that nobody watches run, so the failure modes are silent in both directions: too eager and
 * it takes links people are still using or still asking about, too lazy and the table is an
 * archive of everything anyone has ever shared. The three rows below are the boundary either
 * side of `PRUNE_AFTER_DAYS` and a live link, which is the one that must never be touched.
 *
 * IT IS ALSO A TEST THAT THE COMMAND IS WIRED AT ALL. `MassPrunable` is a trait and a method;
 * nothing fails loudly if the trait is dropped or `prunable()` renamed — the command simply
 * reports nothing to prune, weekly, forever. Running the real artisan command rather than
 * calling `prunable()` directly is what makes that visible.
 */
class PruneSharesTest extends TestCase
{
    use RefreshDatabase;

    /** A share owned by somebody, expiring at `$validUntil`. */
    private function share(string $validUntil): Share
    {
        return Share::factory()->create([
            'user_id' => User::factory()->create()->id,
            'collection_id' => Collection::factory()->create()->id,
            'valid_until' => $validUntil,
        ]);
    }

    public function test_it_sweeps_links_that_died_longer_ago_than_the_grace_period(): void
    {
        $stale = $this->share(now()->subDays(Share::PRUNE_AFTER_DAYS + 1)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => Share::class])->assertSuccessful();

        $this->assertModelMissing($stale);
    }

    public function test_it_leaves_a_recently_expired_link_alone(): void
    {
        // The row a reader is most likely to be asking about — "the link I sent Oma stopped
        // working" — and the whole reason the grace period is not zero.
        $recent = $this->share(now()->subDays(Share::PRUNE_AFTER_DAYS - 1)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => Share::class])->assertSuccessful();

        $this->assertModelExists($recent);
    }

    public function test_it_never_touches_a_link_that_still_works(): void
    {
        // The failure that would matter: a sweep that took live capabilities would break
        // links in strangers' hands, on a schedule, with nobody watching.
        $live = $this->share(now()->addDays(Share::LIFETIME_DAYS)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => Share::class])->assertSuccessful();

        $this->assertModelExists($live);
    }

    public function test_pretend_reports_without_deleting_anything(): void
    {
        // How the timer is checked before it is enabled (the unit file says so), so it had
        // better really be a dry run.
        $stale = $this->share(now()->subDays(Share::PRUNE_AFTER_DAYS + 1)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => Share::class, '--pretend' => true])->assertSuccessful();

        $this->assertModelExists($stale);
    }
}
