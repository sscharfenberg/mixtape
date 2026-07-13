<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | MixTape keeps its Inertia pages under resources/app/pages, not the
    | framework-default resources/js/pages (see the frontend conventions in
    | docs/app-rewrite.md). The `inertia.view-finder` is built from these
    | `paths`/`extensions`, and it backs both the optional runtime page-exists
    | guard and the assertInertia()->component() check in tests — so pointing
    | it here is what lets feature tests assert page components by name.
    |
    | Only the `pages` block is overridden; every other Inertia key (ssr,
    | testing, history, …) falls back to the package defaults, which the
    | service provider merges in beneath this file.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('app/pages'),
        ],

        'extensions' => [
            'js', 'jsx', 'svelte', 'ts', 'tsx', 'vue',
        ],

    ],

];
