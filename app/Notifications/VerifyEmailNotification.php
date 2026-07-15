<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email-verification mail sent on registration (ported from cantrip.me).
 *
 * Overrides Laravel's default VerifyEmail notification to use MixTape's own
 * (German) copy. The signed verification URL is built by
 * VerifyEmail::createUrlUsing() in FortifyServiceProvider, pointing at the named
 * `verify-email` route; this class only shapes the message.
 */
class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  string  $url  The signed email-verification URL.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('E-Mail-Adresse bestätigen')
            ->greeting('Hallo!')
            ->line('Bitte klicke auf die Schaltfläche, um deine E-Mail-Adresse zu bestätigen.')
            ->action('E-Mail-Adresse bestätigen', $url)
            ->line('Wenn du kein Benutzerkonto erstellt hast, ist keine weitere Aktion erforderlich.')
            ->salutation("Mit freundlichen Grüßen,\nDas Team von MixTape");
    }
}
