<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The username-reminder mail sent from the "forgot username" flow (ported
 * from cantrip.me).
 *
 * Unlike the password-reset link this carries no token — it just reminds the
 * account holder of their login name — so it's a plain Notification rather
 * than an override of a Laravel built-in.
 */
class ForgotUsernameNotification extends Notification
{
    /**
     * Determine the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the username-reminder mail message.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ihr Benutzername')
            ->greeting('Hallo!')
            ->line('Du erhältst diese E-Mail, weil wir eine Anfrage zur Erinnerung an deinen Benutzernamen erhalten haben.')
            ->line("Dein Benutzername lautet: {$notifiable->name}")
            ->line('Wenn du diese Anfrage nicht gestellt hast, ist keine weitere Aktion erforderlich.')
            ->salutation("Mit freundlichen Grüßen,\nDas Team von MixTape");
    }
}
