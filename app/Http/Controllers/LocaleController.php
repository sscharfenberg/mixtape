<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Switch the active locale (ported from cantrip.me).
 *
 * The language switcher POSTs here (a plain `fetch`, not an Inertia visit) after
 * it has already flipped vue-i18n client-side, so this only needs to persist the
 * choice: to the DB for authenticated users, to the session for guests. Returns
 * JSON so the fire-and-forget fetch has something to resolve against.
 */
class LocaleController extends Controller
{
    /**
     * @param  string  $locale  the requested locale code (e.g. "de", "en")
     */
    public function update(string $locale): JsonResponse
    {
        if (Locale::tryFrom($locale) === null) {
            return response()->json([], 422);
        }

        if ($userId = Auth::id()) { // persist to the user's column
            Auth::user()->update(['locale' => $locale]);
        } else { // persist to the guest session
            session(['locale' => $locale]);
        }

        app()->setLocale($locale);

        return response()->json(['locale' => $locale]);
    }
}
