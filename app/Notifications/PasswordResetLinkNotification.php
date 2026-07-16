<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password-reset-link mail sent from the "forgot password" flow (ported
 * from cantrip.me).
 *
 * Overrides Laravel's default ResetPassword notification to use MixTape's own
 * (German) copy. The reset URL still resolves via the default `password.reset`
 * named route (routes/web.auth.php), which NewPasswordController::show expects
 * as `email`/`token` query parameters.
 */
class PasswordResetLinkNotification extends ResetPassword
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  string  $url  The password-reset URL.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Passwort zurücksetzen')
            ->greeting('Hallo!')
            ->line('Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passwortes für dein Konto erhalten haben.')
            ->action('Passwort zurücksetzen', $url)
            ->line('Dieser Link zum Zurücksetzen des Passwortes läuft in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' Minuten ab.')
            ->line('Wenn du kein Zurücksetzen des Passwortes angefordert hast, ist keine weitere Aktion erforderlich.')
            ->salutation("Mit freundlichen Grüßen,\nDas Team von MixTape");
    }
}
