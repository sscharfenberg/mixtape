<?php

namespace Tests\Feature\Dev;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dev-only icon gallery (`/icons`) — that it lists the icon directory server-side.
 *
 * Worth a test despite being a dev page, because the thing it guards is invisible from
 * the page itself: the names used to come from an `import.meta.glob`, which quietly cost
 * 55 emitted-and-re-optimized SVG assets in every production build (see IconsController).
 * Anyone reinstating that glob would see an identical-looking gallery, so only a test
 * that pins the names to the *server* keeps the build lean.
 */
class IconsPageTest extends TestCase
{
    // The page renders the app shell, and the header asks the library which areas it
    // holds anything of (HandleInertiaRequests) — so even a page about icons needs the
    // `tracks` table to exist.
    use RefreshDatabase;

    /** The gallery renders and its names are the icon directory's file names, sorted. */
    public function test_it_lists_the_icon_files_as_sprite_symbol_ids(): void
    {
        $expected = collect(File::glob(resource_path('app/assets/icons/*.svg')))
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values()
            ->all();

        // Guards the guard: if the directory were ever empty or misspelled, every
        // assertion below would pass against an empty list and prove nothing.
        $this->assertNotEmpty($expected, 'No icon SVGs found — the icons path is wrong.');

        $this->get('/icons')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dev/IconsPage')
                ->where('iconNames', $expected)
            );
    }

    /**
     * The names are bare symbol ids, not paths or file names.
     *
     * The Icon component feeds each straight into a `<use href="#name">` against the
     * inlined sprite, where icons.ts registered the symbols under exactly this id — a
     * leftover `.svg` or directory prefix would render 55 blanks.
     */
    public function test_the_names_carry_no_extension_or_path(): void
    {
        $this->get('/icons')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $names = $page->toArray()['props']['iconNames'];

                foreach ($names as $name) {
                    $this->assertStringNotContainsString('.', $name);
                    $this->assertStringNotContainsString('/', $name);
                }
            });
    }
}
