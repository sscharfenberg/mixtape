<?php

namespace Tests\Unit\Music;

use App\Services\Music\FannedCovers;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The pick behind a fanned stack of sleeves (CoverSleeves.vue), shared by three pages.
 *
 * A unit test because the rule is arithmetic over an array and has nothing to do with HTTP:
 * the three controllers that call it are covered where they live, and what is pinned here is
 * the thing they all depend on and none of them owns.
 *
 * NO SLEEVE MAY APPEAR TWICE is what most of this file is about, and the two passes catch
 * different duplicates: the KEY collapses one record reached more than once (a playlist's
 * per-track cover URLs, ten of them off one album), the VALUE collapses two records resolving
 * to one picture. The second cannot happen through today's routes — every cover URL carries
 * the id of the row it belongs to — which is exactly why it is worth a test: nothing else
 * would notice if a future route made it possible.
 *
 * AND IT NEVER PADS. One cover fans one sleeve, which in this collection is the COMMON card
 * rather than a degenerate one — half of all artists have a single album.
 */
class FannedCoversTest extends TestCase
{
    /**
     * `[key, url]` pairs, the shape callers pass — one per row they hold, repeats allowed.
     *
     * @param  array<string, string|null>  $byKey
     * @return array<int, array{0: string, 1: string|null}>
     */
    private function pairs(array $byKey): array
    {
        return array_map(fn (string $key, ?string $url): array => [$key, $url], array_keys($byKey), $byKey);
    }

    public function test_it_returns_at_most_three(): void
    {
        $picked = FannedCovers::pick($this->pairs([
            'a' => '/covers/a', 'b' => '/covers/b', 'c' => '/covers/c', 'd' => '/covers/d', 'e' => '/covers/e',
        ]));

        $this->assertCount(3, $picked);
    }

    public function test_an_artless_row_does_not_erase_its_album_s_cover(): void
    {
        /*
         * THE BUG THE PAIR SHAPE EXISTS FOR. A playlist can hold two tracks off one album, one
         * carrying artwork and one not — and with a `[key => url]` map the later entry wins, so
         * whenever the artless one came second the album's cover vanished. The fan then drew two
         * sleeves for three illustrated records: not a crash, not a warning, just one fewer
         * sleeve than there should have been.
         *
         * Asserted in BOTH orders, because the whole failure was that the order decided it.
         */
        $artlessLast = FannedCovers::pick([['album-1', '/covers/one'], ['album-1', null]]);
        $artlessFirst = FannedCovers::pick([['album-1', null], ['album-1', '/covers/one']]);

        $this->assertSame(['/covers/one'], $artlessLast);
        $this->assertSame(['/covers/one'], $artlessFirst);
    }

    public function test_it_never_pads_a_short_stack(): void
    {
        // The one-album artist, which is the commonest card on a genre page. Repeating the
        // sleeve to reach three is the fault this exists to prevent.
        $this->assertSame(['/covers/only'], FannedCovers::pick($this->pairs(['a' => '/covers/only'])));
        $this->assertCount(2, FannedCovers::pick($this->pairs(['a' => '/covers/a', 'b' => '/covers/b'])));
    }

    public function test_it_drops_records_with_no_artwork(): void
    {
        // Dropped rather than fanned as placeholders: two sleeves and a grey square reads as
        // broken, where two sleeves reads as two records.
        $picked = FannedCovers::pick($this->pairs(['a' => '/covers/a', 'b' => null, 'c' => null]));

        $this->assertSame(['/covers/a'], $picked);
    }

    public function test_a_subject_with_no_artwork_at_all_yields_nothing(): void
    {
        // Which the component renders as ONE placeholder — the honest "nothing here" — rather
        // than as a fan of them.
        $this->assertSame([], FannedCovers::pick($this->pairs(['a' => null, 'b' => null])));
    }

    public function test_two_records_resolving_to_one_picture_are_collapsed(): void
    {
        // The VALUE pass. Unreachable through today's routes, and that is the point: the
        // guarantee should not rest on a URL scheme a future route could change.
        $picked = FannedCovers::pick(
            $this->pairs(['a' => '/covers/same', 'b' => '/covers/same', 'c' => '/covers/other'])
        );

        sort($picked);
        $this->assertSame(['/covers/other', '/covers/same'], $picked);
    }

    public function test_the_same_record_reached_twice_is_one_sleeve(): void
    {
        /*
         * The KEY pass, and the playlist page's whole reason for keying at all: several tracks
         * off one album carry DIFFERENT per-track cover URLs pointing at the same picture, so
         * only the key can tell they are one record. Built the way that controller builds it,
         * with a later entry overwriting an earlier one.
         */
        $entries = Collection::make([
            ['albumKey' => 'album-1', 'url' => '/music/songs/track-1/cover'],
            ['albumKey' => 'album-1', 'url' => '/music/songs/track-2/cover'],
            ['albumKey' => 'album-2', 'url' => '/music/songs/track-9/cover'],
        ]);

        $picked = FannedCovers::pick($entries->map(fn (array $e): array => [$e['albumKey'], $e['url']]));

        $this->assertCount(2, $picked);
    }

    public function test_it_takes_a_collection_as_well_as_an_array(): void
    {
        // Two of the three callers already hold a Collection; making them call ->all() first
        // would be ceremony at every site to save an `iterable` hint here.
        $this->assertSame(['/covers/a'], FannedCovers::pick(Collection::make([['a', '/covers/a']])));
    }

    public function test_it_shuffles_rather_than_taking_the_first_three(): void
    {
        /*
         * The fan differs on every visit by design, so a stable pick would be a silent
         * regression — the flourish would still look right in a screenshot and would have
         * stopped doing the one thing it is for.
         *
         * Asserted over repeated calls rather than on one, because any single shuffle may
         * legitimately return the input order. Ten covers taken three at a time gives a
         * 1-in-120 chance per call of matching the head, so twenty calls all matching is about
         * 1 in 10^41 — a fixed seed would be the only realistic way to see this fail.
         */
        $covers = [];
        foreach (range(1, 10) as $n) {
            $covers[] = ["album-{$n}", "/covers/{$n}"];
        }

        $seen = [];
        foreach (range(1, 20) as $ignored) {
            $seen[] = implode(',', FannedCovers::pick($covers));
        }

        $this->assertGreaterThan(1, count(array_unique($seen)));
    }
}
