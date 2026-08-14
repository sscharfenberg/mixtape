<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DeleteAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

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
     * Log the user out, invalidate the session, and delete the record.
     *
     * The password confirmation is DeleteAccountRequest's, per the repo's form-request rule.
     * That request class records the one thing about it worth knowing: why a hand-rolled
     * `Hash::check` here would be the wrong shape even though the modal wants JSON back.
     *
     * What stays here is the SHAPE of the answer, which is not validation. The modal drives
     * this with fetch() rather than an Inertia visit (so a failure cannot scroll the
     * dashboard behind it or pollute the global errors bag), so a JSON caller gets the
     * redirect target as a payload to hand to `router.visit()`; anything else gets a real
     * redirect.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        Auth::guard(config('fortify.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        $request->session()->flash('message', __('flash.account.deleted'));
        $request->session()->flash('type', 'success');

        if ($request->expectsJson()) {
            return response()->json(['redirect' => '/']);
        }

        return redirect('/');
    }
}
