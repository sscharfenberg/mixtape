<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Restate authentication failures onto the dedicated `auth` log channel.
 *
 * This exists so fail2ban has something unambiguous to read. nginx's access log
 * cannot distinguish a failed login from a successful one — Fortify answers both
 * with `POST /login → 302` — so a jail built on that bans real users. The
 * framework's auth events do know the difference.
 *
 * The line format is a published interface: docs/self-hosting/files/
 * mixtape-auth.fail2ban-filter.conf parses it, and AuthFailureLogTest asserts
 * the two still agree. Do not reorder the leading fields.
 *
 *     login.failed ip=203.0.113.9 username="ada" user_id=- route="login.store"
 *     two_factor.failed ip=203.0.113.9 user_id=01h… route="two-factor.login.store"
 *
 * `ip=` comes first and everything after it is scrubbed, because the username is
 * whatever the attacker typed. Left raw, a submitted name containing a newline
 * would forge an entire extra log line — and since fail2ban bans the address it
 * reads out of that line, log injection here is a way to make the server ban an
 * address of the attacker's choosing. Hence scrub() below.
 *
 * Only failures are recorded. Successful logins are not interesting to a jail
 * and would put a valid username next to a valid IP in a file that outlives the
 * session.
 */
class LogAuthenticationFailures
{
    /**
     * Register the events this subscriber handles.
     *
     * Wired explicitly from AppServiceProvider rather than left to event
     * auto-discovery, for the same reason Fortify::ignoreRoutes() is used: the
     * wiring stays greppable end-to-end.
     *
     * The methods below are named record*, not handle*, on purpose. Laravel
     * discovers listeners in app/Listeners by matching any `handle*` method
     * against its type-hint, so a handleFailedLogin() here would be registered
     * twice — once by discovery, once by this subscriber — and every failure
     * would be logged twice. fail2ban counts lines, so that quietly halves the
     * effective maxretry of any jail reading this file.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Failed::class => 'recordFailedLogin',
            TwoFactorAuthenticationFailed::class => 'recordFailedTwoFactor',
        ];
    }

    /**
     * A username/password attempt that did not authenticate.
     *
     * `$event->user` is populated when the account exists but the password was
     * wrong, and null when the username matched nobody — worth keeping, since
     * the two profiles look very different in an attack.
     *
     * Note this does NOT fire for a correct password on an unverified account:
     * EnsureEmailIsVerified throws earlier in the pipeline, so those users are
     * never a candidate for a ban.
     */
    public function recordFailedLogin(Failed $event): void
    {
        Log::channel('auth')->warning(sprintf(
            'login.failed ip=%s username=%s user_id=%s route=%s',
            $this->ip(),
            $this->scrub($event->credentials[Fortify::username()] ?? null),
            $event->user?->getAuthIdentifier() ?? '-',
            $this->scrub($this->routeName()),
        ));
    }

    /**
     * A wrong TOTP code or recovery code at the two-factor challenge.
     *
     * The user is already known here (they cleared the password step), so there
     * is no attacker-supplied username to record — only the account being
     * attacked.
     */
    public function recordFailedTwoFactor(TwoFactorAuthenticationFailed $event): void
    {
        Log::channel('auth')->warning(sprintf(
            'two_factor.failed ip=%s user_id=%s route=%s',
            $this->ip(),
            $event->user?->getAuthIdentifier() ?? '-',
            $this->scrub($this->routeName()),
        ));
    }

    /**
     * The client address, or '-' when there is no request (console, queue).
     *
     * nginx is the edge here and passes the real client address straight through
     * to php-fpm, so this needs no proxy handling. That stops being true the day
     * anything (a CDN, another reverse proxy) sits in front — at which point
     * every ban would target the proxy instead, and TrustProxies must be
     * configured before this log is trusted again.
     */
    private function ip(): string
    {
        return request()->ip() ?? '-';
    }

    private function routeName(): ?string
    {
        return request()->route()?->getName();
    }

    /**
     * Render an untrusted value as a single safe, quoted log token.
     *
     * Strips control characters (newlines above all — see the class docblock on
     * forged lines), bounds the length so one request cannot bloat the file, and
     * JSON-encodes so the result is quoted with any embedded quote escaped.
     *
     * preg_replace returns null on malformed UTF-8 and json_encode returns false
     * on the same; both degrade to an empty quoted token rather than emitting
     * anything unvalidated.
     */
    private function scrub(mixed $value): string
    {
        if (! is_string($value)) {
            return '"-"';
        }

        $stripped = preg_replace('/\p{C}/u', '', $value);
        $encoded = json_encode(mb_substr((string) $stripped, 0, 64), JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '"-"' : $encoded;
    }
}
