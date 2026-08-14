<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dev-only icon gallery (`GET /icons`, route `dev.icons`) — every symbol in the
 * sprite, rendered through the shared Icon component so the page can never drift from
 * what actually ships. Not linked from anywhere, and registered only outside production
 * (see routes/web.php).
 *
 * The icon NAMES are read here, server-side, for a build reason rather than a data one.
 * Harvesting them in the client with `import.meta.glob('…/assets/icons/*.svg')` looks free
 * and is not: a glob — lazy or not — registers every match as a build-time import, so
 * Rollup emits every icon into public/build/assets and vite-plugin-image-optimizer then
 * runs svgo over each one. That is pure waste twice
 * over, because the icons never ship as individual files (resources/build/icons.ts
 * already svgo's them into storage/app/public/sprite.svg, which app.blade.php inlines)
 * and because IconsPage is swept up by main.ts's `pages/**\/*.vue` glob, so production
 * builds paid the cost for a page production cannot even route to.
 *
 * Reading the directory here keeps Vite out of it entirely. It also means the gallery
 * reflects the icon directory at request time, so dropping in a new icon shows up after
 * `npm run icons` alone — no frontend rebuild.
 */
class IconsController extends Controller
{
    /**
     * Render the gallery with the sprite's symbol ids.
     *
     * The ids are the bare file names, which is exactly how icons.ts derives the symbol
     * id it writes into the sprite — same rule in both places, so a name here always
     * resolves to a symbol there.
     */
    public function __invoke(): Response
    {
        $iconNames = collect(File::glob(resource_path('app/assets/icons/*.svg')))
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values();

        return Inertia::render('Dev/IconsPage', [
            'iconNames' => $iconNames,
        ]);
    }
}
