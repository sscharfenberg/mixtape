<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                // Null until a user is logged in. Wired now so guest-only vs
                // authenticated menu items can switch the moment Fortify lands.
                'user' => fn () => $request->user()
                    ? $request->user()->only('id', 'name', 'email')
                    : null,
            ],
            // Placeholder feature flags until Fortify is installed. Once it is,
            // swap these literals for Laravel\Fortify\Features::enabled(...).
            // Open registration stays disabled by design — onboarding is via
            // one-time invite tokens (see CLAUDE.md → Auth & sharing).
            'features' => [
                'registration' => false,
                'resetPasswords' => true,
                'emailVerification' => false,
            ],
        ];
    }
}
