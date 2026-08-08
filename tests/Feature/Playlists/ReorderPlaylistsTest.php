<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The reader's own ordering of their playlists (`PUT /playlists/order`) — what the listing's
 * drag handles write.
 *
 * Three things carry the weight here, and all three are about what an ordering endpoint could
 * be talked into doing:
 *
 *   - IT MUST NOT REACH ANOTHER ACCOUNT. `ids.*` scopes `exists` to the reader, so a foreign
 *     id is a validation failure rather than a row somebody else owns being renumbered. That
 *     is the only genuinely dangerous thing about this route.
 *   - IT MUST NOT ACCEPT A PARTIAL ORDER. Renumbering only the ids sent would leave the rest
 *     on their old numbers, interleaved — an order the reader never asked for.
 *   - IT MUST RENUMBER CONTIGUOUSLY FROM 0, because `position` defaults to 0 for every
 *     playlist, so before the first drag the whole set is ties broken by name. The first
 *     reorder is where real numbers come from.
 */
class ReorderPlaylistsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user with `$names` as playlists, on distinctive starting positions.
     *
     * PINNED, and well away from 0, because PlaylistFactory randomises `position` — which cost
     * two failures before it was noticed. Renumbering writes 0..n-1, so a fixture starting at
     * 90 makes "nothing moved" actually observable: with everything left at the factory's
     * defaults, a rejected request that had gone ahead anyway would be indistinguishable from
     * one that was correctly refused.
     *
     * @param  array<int, string>  $names
     * @return array{User, array<string, Playlist>}
     */
    private function withPlaylists(array $names): array
    {
        $user = User::factory()->create();
        $playlists = [];

        foreach (array_values($names) as $index => $name) {
            $playlists[$name] = Playlist::factory()->create([
                'user_id' => $user->id,
                'name' => $name,
                'position' => 90 + $index,
            ]);
        }

        return [$user, $playlists];
    }

    public function test_guests_cannot_reorder(): void
    {
        [, $playlists] = $this->withPlaylists(['Jazz']);

        $this->put('/playlists/order', ['ids' => [$playlists['Jazz']->id]])->assertRedirect('/login');
    }

    public function test_it_renumbers_the_playlists_in_the_order_given(): void
    {
        [$user, $playlists] = $this->withPlaylists(['Ambient', 'Metal', 'Zydeco']);

        $this->actingAs($user)
            ->from('/playlists')
            ->put('/playlists/order', [
                'ids' => [$playlists['Zydeco']->id, $playlists['Ambient']->id, $playlists['Metal']->id],
            ])
            ->assertRedirect('/playlists');

        $this->assertSame(0, $playlists['Zydeco']->fresh()->position);
        $this->assertSame(1, $playlists['Ambient']->fresh()->position);
        $this->assertSame(2, $playlists['Metal']->fresh()->position);
    }

    public function test_the_listing_comes_back_in_the_new_order(): void
    {
        // The numbers are only worth anything if the page reads them, and the listing sorts on
        // `position` before falling back to `name` — so this is the assertion a reader sees.
        [$user, $playlists] = $this->withPlaylists(['Ambient', 'Metal', 'Zydeco']);

        $this->actingAs($user)->put('/playlists/order', [
            'ids' => [$playlists['Zydeco']->id, $playlists['Metal']->id, $playlists['Ambient']->id],
        ]);

        $this->actingAs($user)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.name', 'Zydeco')
                ->where('playlists.1.name', 'Metal')
                ->where('playlists.2.name', 'Ambient')
            );
    }

    public function test_it_refuses_an_id_belonging_to_someone_else(): void
    {
        [$user, $mine] = $this->withPlaylists(['Mine']);
        [, $theirs] = $this->withPlaylists(['Theirs']);

        $this->actingAs($user)
            ->put('/playlists/order', ['ids' => [$mine['Mine']->id, $theirs['Theirs']->id]])
            ->assertSessionHasErrors('ids.1');

        // And nothing moved — not theirs, and not the reader's own either.
        $this->assertSame(90, $theirs['Theirs']->fresh()->position);
        $this->assertSame(90, $mine['Mine']->fresh()->position);
    }

    public function test_it_refuses_an_id_that_does_not_exist(): void
    {
        [$user, $playlists] = $this->withPlaylists(['Jazz']);

        $this->actingAs($user)
            ->put('/playlists/order', [
                'ids' => [$playlists['Jazz']->id, '019fe0c2-0000-7000-8000-000000000000'],
            ])
            ->assertSessionHasErrors('ids.1');
    }

    public function test_it_refuses_a_partial_order(): void
    {
        // Half a renumbering is the one outcome a reader could not reason about — see the
        // class note.
        [$user, $playlists] = $this->withPlaylists(['Ambient', 'Metal', 'Zydeco']);

        $this->actingAs($user)
            ->put('/playlists/order', ['ids' => [$playlists['Zydeco']->id, $playlists['Ambient']->id]])
            ->assertSessionHasErrors(['ids' => __('playlist.validation')['ids.incomplete']]);

        $this->assertSame(92, $playlists['Zydeco']->fresh()->position);
    }

    public function test_it_refuses_a_duplicated_id(): void
    {
        // Which would otherwise pass the count check while leaving one playlist unnumbered.
        [$user, $playlists] = $this->withPlaylists(['Ambient', 'Metal']);

        $this->actingAs($user)
            ->put('/playlists/order', ['ids' => [$playlists['Ambient']->id, $playlists['Ambient']->id]])
            ->assertSessionHasErrors('ids.1');
    }

    public function test_it_requires_ids_at_all(): void
    {
        [$user] = $this->withPlaylists(['Jazz']);

        $this->actingAs($user)->put('/playlists/order', [])->assertSessionHasErrors('ids');
        $this->actingAs($user)->put('/playlists/order', ['ids' => []])->assertSessionHasErrors('ids');
    }

    public function test_reordering_does_not_touch_names_or_descriptions(): void
    {
        // The endpoint's whole business is `position`; it takes no other field, and a request
        // carrying one must not be able to smuggle it in.
        [$user, $playlists] = $this->withPlaylists(['Ambient', 'Metal']);
        $ambient = $playlists['Ambient'];

        $this->actingAs($user)->put('/playlists/order', [
            'ids' => [$playlists['Metal']->id, $ambient->id],
            'name' => 'Smuggled',
            'description' => 'Smuggled too.',
        ]);

        $ambient->refresh();
        $this->assertSame('Ambient', $ambient->name);
        $this->assertSame(1, $ambient->position);
    }
}
