<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Permanently delete the authenticated user's account (ported from cantrip.me).
 *
 * No soft deletes — this is a hard delete with no recovery path, and there is
 * no user data yet to cascade (playlists / listen history land later, at which
 * point this is where a `$user->delete()` cascade would need to reach them).
 */
class DeleteAccountController extends Controller
{
    /**
     * Checks the current password, logs the user out, invalidates the
     * session, and deletes the user record. Responds with JSON when the
     * request expects it (so the confirmation modal can avoid a full Inertia
     * visit), or with a redirect to the landing page otherwise.
     *
     * The password check is manual rather than `$request->validate()`:
     * bootstrap/app.php only renders validation exceptions as JSON for `api/*`
     * or Precognition requests, so the automatic exception-to-JSON path this
     * route's fetch()-based modal (useDeleteAccount.ts) depends on would
     * otherwise silently fall back to a redirect instead of a 422 body.
     */
    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $password = $request->string('password')->value();

        if ($password === '' || ! Hash::check($password, $user->password)) {
            $message = 'Das Passwort ist nicht korrekt.';

            if ($request->expectsJson()) {
                return response()->json(['errors' => ['password' => [$message]]], 422);
            }

            return back()->withErrors(['password' => $message]);
        }

        Auth::guard(config('fortify.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        $request->session()->flash('message', 'Dein Benutzerkonto wurde gelöscht - du bist jederzeit willkommen zurückzukehren.');
        $request->session()->flash('type', 'success');

        if ($request->expectsJson()) {
            return response()->json(['redirect' => '/']);
        }

        return redirect('/');
    }
}
