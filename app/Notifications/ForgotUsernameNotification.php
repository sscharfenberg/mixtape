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
            ->subject(__('mail.forgot_username.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.forgot_username.line_1'))
            ->line(__('mail.forgot_username.line_name', ['name' => $notifiable->name]))
            ->line(__('mail.forgot_username.line_2'))
            ->salutation(__('mail.salutation'));
    }
}
