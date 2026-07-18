<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PasswordEntropy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "reset password" step of the forgot-password flow (ported from
 * cantrip.me): the link from PasswordResetLinkNotification lands here with
 * `email`/`token` query parameters.
 */
class NewPasswordController extends Controller
{
    /**
     * Display the password reset form.
     *
     * Validates the reset token via Fortify's password broker up front so a
     * dead/expired link bounces straight back to /forgot with an error toast
     * instead of leaving the user on a form that can only fail.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        if (! $user || ! Password::broker(config('fortify.passwords'))->tokenExists($user, $request->string('token')->value())) {
            $request->session()->flash('message', __('passwords.token'));
            $request->session()->flash('type', 'error');

            return redirect()->route('forgot');
        }

        return Inertia::render('Auth/ResetPasswordPage', [
            'email' => $request->string('email')->value(),
            'token' => $request->string('token')->value(),
        ]);
    }

    /**
     * Reset the user's password.
     *
     * Precognitive validation backs the live field feedback / strength meter;
     * the actual reset is delegated to Fortify's password broker, which
     * re-validates the token before invoking the closure below. On success the
     * user is logged straight in and sent to the dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'token' => ['required', 'string'],
                'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
                'password' => ['required', 'string', PasswordRule::default(), new PasswordEntropy],
                'password_confirmation' => ['required', 'string', 'same:password'],
            ]);
        });

        $status = Password::broker(config('fortify.passwords'))->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                Auth::guard(config('fortify.guard'))->login($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // The broker's $status constants are Laravel lang keys; map the
            // (rare) non-success statuses to our own passwords.* lang lines.
            return back()->withErrors(['email' => match ($status) {
                Password::INVALID_TOKEN => __('passwords.token'),
                Password::RESET_THROTTLED => __('passwords.throttled'),
                default => __('passwords.user'),
            }]);
        }

        $request->session()->flash('message', __('passwords.reset'));
        $request->session()->flash('type', 'success');

        return redirect(config('fortify.home'));
    }
}
