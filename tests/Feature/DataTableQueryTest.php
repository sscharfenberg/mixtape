<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a listing does with a query string somebody made up.
 *
 * EVERY LISTING IN THE APP SHARES ONE INPUT SURFACE — `DataTableService` reads `sort`, `dir`,
 * `pageSize` and `search` straight off the request for nine controllers — and it is the one
 * surface with no FormRequest in front of it. That is deliberate: these four answer a bad value
 * by FALLING BACK rather than refusing, because the URL is the table's state and readers share
 * it, so a stale `?sort=bogus` link must render the default sort rather than an error page.
 *
 * "Falls back" is precisely the claim worth testing, though, and it was not true of `search`:
 * an ARRAY value went untouched to the caller's callback and reached
 * `FoldedSearch::fold(?string)`, which is a TypeError — a 500 on every listing, reachable by
 * anyone who can type `?search[]=`. On a box deliberately open to the internet, a crafted URL
 * that 500s is worth a test that walks all of them rather than a fix in one place.
 *
 * Walked as a provider over the real routes rather than asserted once, because the defect was
 * never in a controller: it was in the thing all nine of them share, and a tenth listing added
 * tomorrow inherits both the surface and this test.
 */
class DataTableQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every listing that renders through DataTableService.
     *
     * @return array<string, array<int, string>>
     */
    public static function listings(): array
    {
        return [
            'songs' => ['/music/songs'],
            'albums' => ['/music/albums'],
            'artists' => ['/music/artists'],
            'genres' => ['/music/genres'],
            'playlists' => ['/playlists'],
            'audiobooks' => ['/audiobooks'],
        ];
    }

    /**
     * Query strings that are structurally wrong rather than merely unknown.
     *
     * @return array<string, array<int, string>>
     */
    public static function malformedQueries(): array
    {
        return [
            'search as an array' => ['search[]=a'],
            'search as a nested array' => ['search[nested][]=a'],
            'sort as an array' => ['sort[]=name'],
            'dir as an array' => ['dir[]=asc'],
            'pageSize as an array' => ['pageSize[]=25'],
            'searchIn as an array' => ['searchIn[]=name'],
            'everything at once' => ['search[]=a&sort[]=x&dir[]=y&pageSize[]=z&searchIn[]=w'],
            'an unknown sort key' => ['sort=not-a-column'],
            'an unknown direction' => ['dir=sideways'],
            'a page size nobody offers' => ['pageSize=7'],
        ];
    }

    #[DataProvider('listings')]
    public function test_a_listing_renders_with_a_malformed_query_string(string $listing): void
    {
        $user = User::factory()->create();

        foreach (array_keys(self::malformedQueries()) as $name) {
            $query = self::malformedQueries()[$name][0];

            $this->actingAs($user)
                ->get("{$listing}?{$query}")
                ->assertOk("{$listing} did not survive `{$query}` ({$name}).");
        }
    }

    public function test_an_array_search_is_treated_as_no_search_rather_than_as_a_filter(): void
    {
        /*
         * NOT MERELY "does not 500". A normalisation that turned the array into the string
         * "Array" would also answer 200 — and would then filter the listing by a term nobody
         * typed, showing an empty table that looks like a broken library. The row count has to
         * match the unfiltered page.
         */
        $user = User::factory()->create();

        $unfiltered = $this->actingAs($user)->get('/music/songs');
        $arraySearch = $this->actingAs($user)->get('/music/songs?search[]=a');

        $unfiltered->assertInertia(fn ($page) => $page->has('table.rows'));

        $this->assertSame(
            $this->inertiaProp($unfiltered, 'table.total'),
            $this->inertiaProp($arraySearch, 'table.total'),
            'An array `search` narrowed the listing instead of being ignored.'
        );
    }
}
