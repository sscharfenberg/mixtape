<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password-reset-link mail sent from the "forgot password" flow (ported
 * from cantrip.me).
 *
 * Overrides Laravel's default ResetPassword notification to use MixTape's own
 * copy, resolved via the i18n lang files (mail.*) in the recipient's locale
 * (User implements HasLocalePreference). The reset URL still resolves via the default `password.reset`
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
            ->subject(__('mail.reset_password.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.reset_password.line_1'))
            ->action(__('mail.reset_password.action'), $url)
            ->line(__('mail.reset_password.line_expires', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('mail.reset_password.line_2'))
            ->salutation(__('mail.salutation'));
    }
}
