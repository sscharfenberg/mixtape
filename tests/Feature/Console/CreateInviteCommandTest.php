<?php

namespace Tests\Feature\Console;

use App\Models\Invite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `app:invite` — the ONLY way an account comes into existence here.
 *
 * Open registration is disabled by design, so every user on this instance arrived through a
 * link this command printed. `RegisterTest` proves the redemption half thoroughly, but it
 * builds its invites by hand with `Invite::create` + `Invite::hashCode` — so the MINTING half
 * had no coverage at all, and the two failure modes it hides are opposite and both bad:
 * nobody can be invited, or a usable code is sitting in plaintext in a database column.
 *
 * The two halves are therefore joined at the end: the URL this command prints is fed to the
 * real registration flow. A test that only inspected the row would pass on a link whose `code`
 * parameter the register page cannot actually redeem.
 */
class CreateInviteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_only_a_hash_and_never_the_plaintext_code(): void
    {
        /*
         * THE ONE PROPERTY WORTH MOST. A leaked or dumped `invites` table must not hand anybody
         * a working registration link — which is the whole reason the column holds a digest
         * rather than the code. Asserted by pulling the code back out of the printed URL and
         * showing it appears nowhere in the row.
         */
        $code = $this->codeFromOutput('app:invite', ['note' => 'For Ada']);

        $invite = Invite::query()->sole();

        $this->assertNotSame($code, $invite->token);
        $this->assertSame(Invite::hashCode($code), $invite->token);
        $this->assertStringNotContainsString(
            $code,
            json_encode($invite->getAttributes(), JSON_THROW_ON_ERROR),
            'The plaintext code is recoverable from the stored row.'
        );
    }

    public function test_the_printed_link_is_one_the_register_page_actually_accepts(): void
    {
        // The join between the two halves: a link that mints cleanly but cannot be redeemed
        // is the failure neither this command's own assertions nor RegisterTest would catch.
        $code = $this->codeFromOutput('app:invite', ['note' => 'For Ada']);

        $this->get(route('register', ['code' => $code]))->assertOk();

        $this->post('/register', [
            'code' => $code,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'korrekt-pferd-batterie',
            'password_confirmation' => 'korrekt-pferd-batterie',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['name' => 'Ada']);
        // Spent on success — a one-time invite that survived redemption is a reusable one.
        $this->assertSame(0, Invite::query()->count());
    }

    public function test_it_dates_the_invite_by_the_days_option(): void
    {
        $this->artisan('app:invite', ['note' => 'Short', '--days' => 3])->assertSuccessful();

        $invite = Invite::query()->sole();

        // To the minute rather than the second: the row is written a beat after `now()` is read.
        $this->assertSame(
            now()->addDays(3)->format('Y-m-d H:i'),
            $invite->valid_until->format('Y-m-d H:i')
        );
    }

    public function test_it_refuses_a_validity_window_of_less_than_a_day(): void
    {
        // `--days=0` would mint an invite already past its own expiry — a link that is dead
        // before it is sent, which reads as the invite system being broken.
        $this->artisan('app:invite', ['note' => 'Nope', '--days' => 0])->assertFailed();

        $this->assertSame(0, Invite::query()->count());
    }

    public function test_a_blank_note_is_stored_as_nothing_rather_than_as_an_empty_string(): void
    {
        // The note is a reminder for whoever minted it; "" and null both mean "none", and two
        // ways of saying none is what makes a listing show a stray empty row.
        $this->artisan('app:invite', ['note' => '   ', '--days' => 7])->assertSuccessful();

        $this->assertNull(Invite::query()->sole()->note);
    }

    public function test_two_invites_never_share_a_code(): void
    {
        $first = $this->codeFromOutput('app:invite', ['note' => 'One']);
        $second = $this->codeFromOutput('app:invite', ['note' => 'Two']);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Invite::query()->distinct()->count('token'));
    }

    /**
     * Run the command and pull the plaintext code back out of the link it printed.
     *
     * The code exists nowhere else by design — the row holds only its hash — so reading the
     * output is the only way a test can get at it, which is exactly the position the
     * administrator who runs this command is in.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function codeFromOutput(string $command, array $arguments): string
    {
        // `Artisan::call` rather than `$this->artisan()`, because only the former leaves its
        // output where `Artisan::output()` can read it — the test helper buffers into its own
        // PendingCommand, so the facade returns an empty string however the command went.
        $this->assertSame(0, Artisan::call($command, $arguments + ['--days' => 7]));

        $output = Artisan::output();

        $this->assertSame(
            1,
            preg_match('/[?&]code=([A-Za-z0-9]+)/', $output, $matches),
            "No ?code= link in the command's output:\n".$output
        );

        return $matches[1];
    }
}
