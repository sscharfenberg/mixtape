<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Enums\TrackType;
use App\Models\Track;
use App\Services\Player\PlayerStatePayload;
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
     * Which media kinds the library holds anything of.
     *
     * Keyed by the enum's own values so a new kind reaches the client by existing, and
     * `false` for a kind with no rows rather than an absent key — the header reads it as a
     * boolean and an `undefined` would light the link up.
     *
     * @return array<string, bool>
     */
    private static function areasWithTracks(): array
    {
        $present = Track::query()->distinct()->pluck('type');

        return collect(TrackType::cases())
            ->mapWithKeys(fn (TrackType $type) => [$type->value => $present->contains($type)])
            ->all();
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
            // Active locale (resolved by ConfigureLocale, which runs first) and
            // the supported set. Only the locale string is shared — the message
            // catalogs are code-split JSON the client loads on demand (see i18n.ts).
            'locale' => app()->getLocale(),
            'supportedLocales' => Locale::values(),
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
                'twoFactorAuthentication' => Features::enabled(Features::twoFactorAuthentication()),
            ],
            // WHICH AREAS THE LIBRARY ACTUALLY HAS, so the header offers Music only to a
            // collection with music in it and Audiobooks only to one with audiobooks —
            // an empty area is a link to a page saying nothing, and this instance may
            // legitimately hold one kind and not the other.
            //
            // ONE `DISTINCT` RATHER THAN TWO `EXISTS`, and no cache. The column is the
            // leading half of the `(type, created_at)` index, so this is an index scan
            // over a handful of distinct values — cheaper than the round trip that
            // carries it. A cache would have to be invalidated by the nightly scan, and
            // its first failure mode is the worst one this feature has: importing a
            // library and finding the menu still empty.
            'library' => fn (): array => self::areasWithTracks(),
            // Player settings the CLIENT has to honour but the server owns. Only the
            // position heartbeat so far: the browser runs the clock (it is the only thing
            // that knows whether audio is playing), and this is the operator's say in how
            // often it writes. Not a closure — it is one integer off a cached config.
            'player' => [
                'positionHeartbeat' => (int) config('mixtape.player.position_heartbeat'),
            ],
            // The play queue this user left behind, restored from `player_states`
            // (data-model.md → "the play queue"). ON A FULL PAGE LOAD ONLY, which is both
            // an economy and the truth: `usePlayerQueue.hydrate()` runs once, from
            // FullLayout, and that layout is persistent — so a client-side visit has a live
            // queue in memory that this prop could only contradict. Sending it anyway would
            // put a queue's worth of JSON on every navigation to be thrown away.
            // Null for a guest, and null when the user has no stored queue — which the
            // client reads as "keep whatever is in localStorage" rather than "empty it".
            'playerState' => fn () => $request->header('X-Inertia')
                ? null
                : PlayerStatePayload::forUser($request->user()),
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
