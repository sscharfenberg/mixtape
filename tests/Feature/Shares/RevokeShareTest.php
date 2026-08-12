<?php

namespace Tests\Feature\Shares;

use App\Models\Collection;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * REVOKING A SHARE LINK — `GET /dashboard/shared` and `DELETE /shares/{share}`
 * (docs/sharing.md → "Revoking"), the half the share modal has been promising since minting
 * was built.
 *
 * WHAT IS PINNED HERE is the part that is a permission rather than a page:
 *
 *   - REVOKING REALLY REVOKES. Deleting the row is the whole mechanism, so the test that
 *     matters is not "the row is gone" but that every `/s/` route stops answering — the page
 *     and the media routes alike, for a caller with no session.
 *   - ONLY THE MINTER MAY, and somebody else's link answers 404 rather than 403. A 403 would
 *     confirm that the id names a real, live share belonging to another account, which on an
 *     instance shared between family and friends is a disclosure.
 *   - THE LIST IS THE READER'S OWN, and shows expired links too — they are still rows the
 *     reader made, still revocable, and a list that quietly shrank would read as links going
 *     missing.
 *
 * The `shares` shared prop that gates both entry points is asserted here as well, because it
 * is the same question ("has this reader shared anything?") asked from a different place, and
 * a menu entry that disagrees with the page behind it is the bug worth catching.
 */
class RevokeShareTest extends TestCase
{
    use RefreshDatabase;

    /** A user, an album with one track, and a live share of it owned by that user. */
    private function shared(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $album = Collection::factory()->create(['name' => 'OK Computer']);
        Track::factory()->create(['collection_id' => $album->id]);

        return [$owner, Share::factory()->create(['user_id' => $owner->id, 'collection_id' => $album->id])];
    }

    public function test_the_reader_sees_their_own_links_with_what_each_one_is(): void
    {
        [$user, $share] = $this->shared();

        $this->actingAs($user)
            ->get('/dashboard/shared')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard/Shares/SharesPage')
                ->has('shares', 1)
                ->where('shares.0.id', $share->id)
                // The kind travels apart from the name because the page draws it as a pip
                // beside one — a pre-formatted string would be a translation the server has
                // no business making.
                ->where('shares.0.kind', 'album')
                ->where('shares.0.name', 'OK Computer')
                // ABSOLUTE, because the copy button puts it straight into a chat window — a
                // root-relative path is not a link there. The same string the mint response
                // hands back, so the two places a reader copies from cannot differ.
                ->where('shares.0.url', $share->url())
                ->where('shares.0.expired', false)
            );
    }

    public function test_it_lists_expired_links_too_so_they_can_be_tidied_away(): void
    {
        [$user, $share] = $this->shared();
        $share->update(['valid_until' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/dashboard/shared')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shares', 1)
                ->where('shares.0.expired', true)
            );
    }

    public function test_it_never_shows_one_reader_another_readers_links(): void
    {
        [, $share] = $this->shared();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('/dashboard/shared')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('shares', 0));

        $this->assertModelExists($share);
    }

    public function test_revoking_stops_the_link_working_for_everybody(): void
    {
        [$user, $share] = $this->shared();
        $track = Track::query()->firstOrFail();

        // It worked a moment ago — otherwise the assertions below prove nothing.
        $this->get("/s/{$share->id}")->assertOk();

        $this->actingAs($user)->delete("/shares/{$share->id}")->assertRedirect();

        $this->assertModelMissing($share);
        // EVERY route under `/s/` binds the row, so one delete closes all of them — and to a
        // stranger it is the same 404 a typo gets, which is the intent.
        $this->get("/s/{$share->id}")->assertNotFound();
        $this->get("/s/{$share->id}/cover")->assertNotFound();
        $this->get("/s/{$share->id}/tracks/{$track->id}/stream")->assertNotFound();
        $this->get("/s/{$share->id}/tracks/{$track->id}/cover")->assertNotFound();
    }

    public function test_a_stranger_cannot_revoke_a_link_and_is_not_told_it_exists(): void
    {
        [, $share] = $this->shared();
        $stranger = User::factory()->create();

        // 404 rather than 403: "you may not revoke that" confirms the id names a real, live
        // share belonging to somebody else.
        $this->actingAs($stranger)->delete("/shares/{$share->id}")->assertNotFound();

        $this->assertModelExists($share);
    }

    public function test_a_guest_cannot_revoke_anything(): void
    {
        [, $share] = $this->shared();

        $this->delete("/shares/{$share->id}")->assertRedirect('/login');

        $this->assertModelExists($share);
    }

    public function test_the_shared_prop_says_whether_there_is_anything_to_manage(): void
    {
        [$user, $share] = $this->shared();

        // Both entry points — the dashboard section and the user-menu link — are drawn off
        // this one boolean, so it has to agree with the page behind it.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shares', true));

        $share->delete();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shares', false));
    }

    public function test_an_expired_link_still_counts_as_something_to_manage(): void
    {
        [$user, $share] = $this->shared();
        $share->update(['valid_until' => now()->subDay()]);

        // Hiding the way in would leave a reader holding dead rows and no way to tidy them.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shares', true));
    }
}
