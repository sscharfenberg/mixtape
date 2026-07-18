<?php

namespace App\Console\Commands;

use App\Models\Invite;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Mints a one-time, expiring registration invite and prints a link to share.
 *
 * Onboarding is invite-only (open registration is disabled), so this is how new
 * accounts are seeded: the owner runs the command, copies the printed URL, and
 * sends it to the person being onboarded. The recipient opens the link, sets a
 * username + e-mail + password, and the invite is spent (deleted) on success.
 *
 * Only the sha256 hash of the code is stored; the plaintext lives solely in the
 * printed link, so if it is lost it cannot be recovered — mint a new one.
 */
class CreateInvite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:invite
                            {note? : Optional reminder of who the invite is for}
                            {--days=7 : Number of days the invite stays valid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mint a one-time registration invite link';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error(__('invite.days_invalid'));

            return self::FAILURE;
        }

        // When no note is passed on the command line, ask for one interactively;
        // a blank answer (just pressing enter) stores no note.
        $note = $this->argument('note') ?? text(
            label: __('invite.note_label'),
            placeholder: __('invite.note_placeholder'),
            hint: __('invite.note_hint'),
        );
        $note = ($note === null || trim($note) === '') ? null : trim($note);

        // High-entropy, URL-safe plaintext. Only its sha256 hash is stored.
        $code = Str::random(40);

        Invite::create([
            'token' => Invite::hashCode($code),
            'note' => $note,
            'valid_until' => now()->addDays($days),
        ]);

        $url = route('register', ['code' => $code]);

        $this->newLine();
        $this->info(trans_choice('invite.minted', $days, ['count' => $days]));
        if ($note !== null) {
            $this->line('  '.__('invite.note_line', ['note' => $note]));
        }
        $this->newLine();
        $this->line(__('invite.link_intro'));
        $this->line('  '.$url);
        $this->newLine();

        return self::SUCCESS;
    }
}
