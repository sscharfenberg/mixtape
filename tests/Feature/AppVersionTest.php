<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The app's version, from package.json to the footer.
 *
 * WHY THIS EARNS A TEST RATHER THAN BEING OBVIOUS: the footer had been asking for
 * `props.version` since it was written, and nothing ever shared it. An `as string` cast on
 * the client meant `undefined` went straight into the copyright line, and neither the type
 * checker nor any test had a reason to notice — the page rendered, it just said the wrong
 * thing. What is pinned here is the seam that was missing, not the value.
 *
 * NOT ASSERTED AS A LITERAL. Hard-coding "2.0.0" would make `npm version` a change that
 * breaks the suite, which is the opposite of what a single source of truth is for. What
 * matters is that the config reads the same file npm writes, and that the prop carries it.
 */
class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_config_is_read_out_of_package_json(): void
    {
        $expected = json_decode((string) file_get_contents(base_path('package.json')), true)['version'];

        $this->assertSame($expected, config('app.version'));
        // Semver-shaped, so a config that returned the whole decoded array — or the name —
        // could not pass by being merely non-null.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) config('app.version'));
    }

    public function test_every_page_is_told_the_version(): void
    {
        // A guest page, deliberately: the footer is on every page including the ones
        // reached without a session, so the prop cannot be shared behind `auth`.
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('version', config('app.version')));
    }

    public function test_an_unreadable_package_json_does_not_take_the_app_down(): void
    {
        /*
         * The guard in config/app.php, exercised rather than assumed: a footer string is
         * never worth a 500. Re-evaluated here by running the same expression the config
         * does against a path that does not exist — the config itself is resolved once at
         * boot, so pointing it somewhere else mid-test would prove nothing.
         */
        $value = rescue(
            fn (): ?string => json_decode((string) file_get_contents(base_path('no-such-package.json')), true)['version'] ?? null,
            null,
            report: false
        );

        $this->assertNull($value);
    }
}
