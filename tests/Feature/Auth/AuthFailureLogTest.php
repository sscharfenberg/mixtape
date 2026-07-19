<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Tests\TestCase;

/**
 * The dedicated `auth` log channel that fail2ban parses.
 *
 * These tests exist because the log line is an interface, not an implementation
 * detail: /etc/fail2ban/filter.d/mixtape-auth.conf greps it, and a jail that
 * silently stops matching looks exactly like a jail with nothing to ban. So the
 * regex below is kept a mirror of the one in
 * docs/self-hosting/files/mixtape-auth.fail2ban-filter.conf — change one, change
 * both, and this suite is what tells you that you didn't.
 *
 * The channel writes to a real temp file rather than a Log fake, because half of
 * what is under test (quoting, injection, the password never landing) only
 * exists once the line has actually been formatted and written.
 */
class AuthFailureLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirror of the fail2ban `failregex` for failed logins, with <HOST>
     * expanded to a plain address pattern.
     */
    private const LOGIN_FAILREGEX = '/\blogin\.failed ip=(?P<host>[0-9a-fA-F:.]+)\b/';

    private const TWO_FACTOR_FAILREGEX = '/\btwo_factor\.failed ip=(?P<host>[0-9a-fA-F:.]+)\b/';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = sys_get_temp_dir().'/mixtape-auth-'.Str::random(12).'.log';
        config()->set('logging.channels.auth.path', $this->logPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    private function authLog(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function test_a_failed_login_writes_one_parseable_line(): void
    {
        User::factory()->create([
            'name' => 'Ada Lovelace',
            'password' => Hash::make('s3cret-pass'),
        ]);

        $this->post('/login', ['name' => 'Ada Lovelace', 'password' => 'wrong-pass']);

        $log = $this->authLog();

        $this->assertSame(1, preg_match_all(self::LOGIN_FAILREGEX, $log, $matches));
        $this->assertSame('127.0.0.1', $matches['host'][0]);
        $this->assertStringContainsString('username="Ada Lovelace"', $log);
    }

    public function test_an_unknown_username_is_recorded_without_a_user_id(): void
    {
        $this->post('/login', ['name' => 'nobody', 'password' => 'whatever']);

        $log = $this->authLog();

        $this->assertMatchesRegularExpression(self::LOGIN_FAILREGEX, $log);
        $this->assertStringContainsString('user_id=-', $log);
    }

    public function test_a_successful_login_writes_nothing(): void
    {
        User::factory()->create([
            'name' => 'Grace Hopper',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->post('/login', ['name' => 'Grace Hopper', 'password' => 'correct-horse']);

        $this->assertSame('', $this->authLog());
    }

    public function test_the_submitted_password_never_reaches_the_log(): void
    {
        User::factory()->create([
            'name' => 'Ada Lovelace',
            'password' => Hash::make('s3cret-pass'),
        ]);

        $this->post('/login', ['name' => 'Ada Lovelace', 'password' => 'hunter2-do-not-log']);

        $this->assertStringNotContainsString('hunter2-do-not-log', $this->authLog());
    }

    /**
     * The one that matters most.
     *
     * fail2ban bans whatever address it reads out of a matching line, so a
     * username able to forge a line is a way to make the server ban an arbitrary
     * third party. The forged text must survive only as inert quoted content.
     */
    public function test_a_username_cannot_forge_a_second_log_line(): void
    {
        $forged = "x\n[2026-07-19T00:00:00.000000+00:00] auth.WARNING: login.failed ip=198.51.100.7 username=\"z\"";

        $this->post('/login', ['name' => $forged, 'password' => 'whatever']);

        $log = $this->authLog();

        $this->assertSame(1, preg_match_all(self::LOGIN_FAILREGEX, $log, $matches));
        $this->assertSame('127.0.0.1', $matches['host'][0]);
        $this->assertStringNotContainsString('198.51.100.7', $log);
        $this->assertSame(1, substr_count(rtrim($log, "\n"), "\n") + 1, 'expected exactly one line');
    }

    /**
     * The User-Agent is the stand-in for logging the submitted password: it
     * answers "human or script" without putting a secret on disk.
     */
    public function test_the_user_agent_is_recorded_and_scrubbed(): void
    {
        $this->withHeader('User-Agent', "python-requests/2.32\nforged")
            ->post('/login', ['name' => 'nobody', 'password' => 'whatever']);

        $log = $this->authLog();

        $this->assertStringContainsString('ua="python-requests/2.32forged"', $log);
        $this->assertSame(1, preg_match_all(self::LOGIN_FAILREGEX, $log));
    }

    public function test_an_overlong_username_is_truncated(): void
    {
        $this->post('/login', ['name' => str_repeat('a', 5000), 'password' => 'whatever']);

        $this->assertLessThan(400, strlen($this->authLog()));
    }

    public function test_a_failed_two_factor_challenge_is_recorded(): void
    {
        $user = User::factory()->create();

        TwoFactorAuthenticationFailed::dispatch($user);

        $log = $this->authLog();

        $this->assertMatchesRegularExpression(self::TWO_FACTOR_FAILREGEX, $log);
        $this->assertStringContainsString('user_id='.$user->getAuthIdentifier(), $log);
    }
}
