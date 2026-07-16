<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ForgotUsernameNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password / username" (ported from cantrip.me). One page, one route,
 * a `type` field toggles which recovery is requested: `password` sends a
 * Fortify password-reset link (requires the username too, not just the
 * email — an extra check beyond Fortify's own broker), `name` emails a
 * reminder of the account's username. Both branches always flash the same
 * success message regardless of whether a matching user exists, so the form
 * can't be used to enumerate registered emails.
 */
class ForgotController extends Controller
{
    /**
     * Display the "Forgot password / username" page.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPage');
    }

    /**
     * Handle a "forgot password" or "forgot username" form submission.
     *
     * Precognitive so the form can live-validate a single field on blur
     * without dispatching an email.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'type' => ['required', 'in:password,name'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['required_if:type,password', 'string', 'min:3', 'max:80'],
            ]);
        });

        return match ($request->string('type')->value()) {
            'password' => $this->sendPasswordResetLink($request),
            'name' => $this->sendUsernameReminder($request),
        };
    }

    /**
     * Send a password reset link via Fortify's password broker.
     *
     * Only dispatched when a user matching both the given name AND email
     * exists; always flashes success either way to prevent email enumeration.
     */
    private function sendPasswordResetLink(Request $request): RedirectResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->value())
            ->where('name', $request->string('name')->value())
            ->first();

        if ($user) {
            Password::broker(config('fortify.passwords'))->sendResetLink($request->only('email'));
        }

        $request->session()->flash('message', 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Link zum Zurücksetzen des Passwortes gesendet.');
        $request->session()->flash('type', 'success');

        return redirect()->route('home');
    }

    /**
     * Send a username-reminder notification to the given email address.
     *
     * Only dispatched when a matching user exists; always flashes success
     * either way to prevent email enumeration.
     */
    private function sendUsernameReminder(Request $request): RedirectResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        $user?->notify(new ForgotUsernameNotification);

        $request->session()->flash('message', 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir eine Benutzername-Erinnerung gesendet.');
        $request->session()->flash('type', 'success');

        return redirect()->route('home');
    }
}
