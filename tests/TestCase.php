<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * One Inertia prop, by dot path, with a failure that names it.
     *
     * `assertInertia` is the right tool for asserting a prop, and this is for the other case:
     * CAPTURING one to compare against something else — two responses' totals, a value the test
     * then sorts, an id it needs for a second request. The fluent assertion cannot hand a value
     * back, so those tests reached through `->viewData('page')['props'][...]` instead.
     *
     * The reach works and loses the diagnostics: a renamed or missing prop surfaces as "Undefined
     * array key", naming neither the prop nor the page, several frames from the test. Here the
     * failure says which path was absent and lists what the page actually sent.
     *
     * @param  string  $path  dot path into the props, e.g. `table.total` or `songs.popular.0.id`
     */
    protected function inertiaProp(TestResponse $response, string $path): mixed
    {
        $page = $response->viewData('page');

        $this->assertIsArray($page, 'The response did not render an Inertia page at all.');

        $value = data_get($page['props'] ?? [], $path, $missing = new \stdClass);

        $this->assertNotSame($missing, $value, sprintf(
            "The Inertia page has no prop `%s`.\nComponent: %s\nProps present: %s",
            $path,
            $page['component'] ?? '(none)',
            implode(', ', array_keys($page['props'] ?? [])) ?: '(none)'
        ));

        return $value;
    }
}
