<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Resend verification email" (ported from cantrip.me). Lets a user whose
 * verification link expired request a fresh one, without needing to log in
 * (they can't — an unverified account is blocked at login).
 */
class ResendVerificationController extends Controller
{
    /**
     * Display the "resend verification email" page.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ResendVerificationPage');
    }

    /**
     * Handle a "resend verification email" request.
     *
     * Requires both name AND email to match the same user (harder to
     * enumerate accounts than email alone), and only sends the notification
     * if that user hasn't already verified. Always flashes the same generic
     * success message regardless of outcome to prevent enumeration.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'name' => ['required', 'string', 'min:3', 'max:80'],
                'email' => ['required', 'string', 'email', 'max:255'],
            ]);
        });

        $user = User::query()
            ->where('email', $request->string('email')->value())
            ->where('name', $request->string('name')->value())
            ->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        $request->session()->flash('message', __('flash.email.verification_resent'));
        $request->session()->flash('type', 'success');

        return redirect()->route('home');
    }
}
