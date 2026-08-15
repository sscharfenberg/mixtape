<?php

namespace Tests\Feature\Music\Concerns;

use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson as Assert;

/**
 * Ask a listing for every column it sorts by, in both directions, and prove the pair reverses.
 *
 * THE ASSERTION IS "IT REVERSED", not "this row came first", and that is what makes one helper
 * serve four listings. Which row leads depends on the column — `name` puts Alpha above Zeta
 * while `songs` puts the small one first — so hard-coding a winner would need a table of
 * expectations per listing, and would break every time a fixture was retuned. A sort that ran
 * correctly always produces mirror images; a sort that silently did nothing produces the same
 * order twice.
 *
 * This is the test that catches ORDER BY on a sub-query alias failing, which is how most of
 * these columns are computed.
 *
 * WHAT IT CANNOT CHECK, and why each listing still builds its own fixture: every sorted-on
 * value has to DIFFER between the two rows, including the ones easiest to leave equal — the
 * disc count, the file dates. A tie sorts stably, so a tied column "does not reverse" and reads
 * as a broken sort when it is really a broken fixture. Only the listing knows which of its
 * columns those are, so the rows stay where they are and only the interrogation is shared.
 */
trait SortsAListing
{
    /**
     * @param  string  $listing  the URL to ask, e.g. `/music/albums`
     * @param  list<string>  $keys  every `sort=` this listing accepts
     * @param  list<string>  $twoIds  the ids of the two rows, in either order
     */
    protected function assertEverySortableColumnReverses(User $user, string $listing, array $keys, array $twoIds): void
    {
        $this->assertCount(2, $twoIds, 'The reversal check needs exactly two rows to swap.');

        foreach ($keys as $key) {
            $ascending = $this->actingAs($user)->get("{$listing}?sort={$key}&dir=asc");
            $descending = $this->actingAs($user)->get("{$listing}?sort={$key}&dir=desc");

            $ascending->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                // The response says which sort it applied, so a key the listing quietly
                // ignored fails here rather than looking like a sort that did not reverse.
                ->where('table.sort.key', $key)
                ->where('table.sort.direction', 'asc')
            );

            $first = $this->inertiaProp($ascending, 'table.rows.0.id');
            $last = $this->inertiaProp($descending, 'table.rows.1.id');

            $this->assertSame($first, $last, "sorting by {$key} did not reverse");
            $this->assertContains($first, $twoIds, "sorting by {$key} returned a row the fixture did not create");
        }
    }
}
