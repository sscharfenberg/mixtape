<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

/**
 * Login-pipeline step (ported from cantrip.me): rejects a login attempt when the account
 * exists but its e-mail address is not yet verified. Registration creates unverified users
 * and sends a verification e-mail, so this is what makes "verify before you can log in"
 * actually bite. Only active while the email-verification feature is enabled
 * (config/fortify.php).
 *
 * IT PROVES THE PASSWORD BEFORE IT SAYS ANYTHING, and that is the whole shape of this class.
 * A step that looks the user up by name alone and refuses turns `POST /login` into an account
 * oracle: any password at all answers "not verified" for an unverified account, where every
 * other outcome answers "credentials incorrect". This instance logs in BY NAME, so that is
 * half a credential pair confirmed to anyone who asks — the same disclosure `ForgotController`
 * goes to deliberate lengths to avoid on its own form.
 *
 * WHY THE CHECK IS NOT SIMPLY MOVED AFTER `AttemptToAuthenticate`, which is the obvious way to
 * get the same guarantee: `RedirectIfTwoFactorAuthenticatable` runs BEFORE it and
 * short-circuits into the two-factor challenge, so a user with 2FA never reaches
 * `AttemptToAuthenticate` at all. That state is reachable — `UpdateUserProfileInformation`
 * clears `email_verified_at` when the address changes, so a verified user with 2FA who edits
 * their e-mail is unverified and still holds a second factor. Moving the step would let
 * exactly that person complete the challenge and log in unverified: a bypass, in exchange for
 * closing a disclosure. Validating here keeps ONE gate in front of BOTH login paths.
 *
 * A wrong password falls through to the pipeline rather than being refused here, so the
 * generic "credentials incorrect" still comes from `AttemptToAuthenticate` (or from the 2FA
 * step) exactly as before, from one place. Nothing here fires a failed-login event or touches
 * the throttle: this step is a gate, and letting it also count attempts would double every
 * failure against the limiter.
 */
class EnsureEmailIsVerified
{
    /**
     * Handle the pipeline step.
     *
     * @throws ValidationException when the password is correct and the account is unverified
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! Features::enabled(Features::emailVerification())) {
            return $next($request);
        }

        $user = User::query()
            ->where(Fortify::username(), $request->{Fortify::username()})
            ->first();

        if ($user !== null && ! $user->hasVerifiedEmail() && $this->passwordIsCorrect($request, $user)) {
            throw ValidationException::withMessages([
                Fortify::username() => [__('auth.email_not_verified')],
            ]);
        }

        return $next($request);
    }

    /**
     * Whether the submitted password really belongs to `$user`.
     *
     * Asked through the guard's own UserProvider rather than by hashing here, so this agrees
     * with however the app is configured to check a password — including a driver change or a
     * rehash policy — and cannot drift from what `AttemptToAuthenticate` will conclude a step
     * later. Returns false for a missing password, which is the same answer a wrong one gets.
     */
    private function passwordIsCorrect(Request $request, User $user): bool
    {
        $password = $request->input('password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        $provider = Auth::guard(config('fortify.guard'))->getProvider();

        return $provider instanceof UserProvider
            && $provider->validateCredentials($user, ['password' => $password]);
    }
}
