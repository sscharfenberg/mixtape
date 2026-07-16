<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Fortify\Features;

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
            'csrfToken' => csrf_token(),
            'auth' => [
                // Null until a user is logged in — drives guest-only vs.
                // authenticated menu items (see UserMenu.vue).
                'user' => fn () => $request->user()
                    ? $request->user()->only('id', 'name', 'email')
                    : null,
            ],
            // Real Fortify feature flags (see config/fortify.php). The UserMenu /
            // auth-page links gate on these, so they light up automatically
            // whenever a feature is switched on there.
            'features' => [
                'registration' => Features::enabled(Features::registration()),
                'resetPasswords' => Features::enabled(Features::resetPasswords()),
                'emailVerification' => Features::enabled(Features::emailVerification()),
            ],
            // Session flash bridged into the Vue toast (see ToastContainer.vue).
            // `nonce` is a fresh per-response token whenever a message exists, so
            // the toast watcher fires even for two identical messages in a row.
            'flash' => fn () => [
                'message' => $request->session()->get('message'),
                'type' => $request->session()->get('type'),
                'duration' => $request->session()->get('duration'),
                'nonce' => $request->session()->has('message') ? uniqid('', true) : null,
            ],
        ];
    }
}
