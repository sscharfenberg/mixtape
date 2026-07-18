<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email-verification mail sent on registration (ported from cantrip.me).
 *
 * Overrides Laravel's default VerifyEmail notification to use MixTape's own
 * copy, resolved via the i18n lang files (mail.*) in the recipient's locale
 * (User implements HasLocalePreference). The signed verification URL is built by
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
            ->subject(__('mail.verify_email.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.verify_email.line_1'))
            ->action(__('mail.verify_email.action'), $url)
            ->line(__('mail.verify_email.line_2'))
            ->salutation(__('mail.salutation'));
    }
}
