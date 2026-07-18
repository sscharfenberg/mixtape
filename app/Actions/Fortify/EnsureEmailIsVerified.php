<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

/**
 * Login-pipeline step (ported from cantrip.me): rejects a login attempt when the
 * account exists but its e-mail address is not yet verified. Registration creates
 * unverified users and sends a verification e-mail, so this is what makes "verify
 * before you can log in" actually bite. Only active while the email-verification
 * feature is enabled (config/fortify.php).
 */
class EnsureEmailIsVerified
{
    /**
     * Handle the pipeline step.
     *
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! Features::enabled(Features::emailVerification())) {
            return $next($request);
        }

        $user = User::where(Fortify::username(), $request->{Fortify::username()})->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                Fortify::username() => [__('auth.email_not_verified')],
            ]);
        }

        return $next($request);
    }
}
