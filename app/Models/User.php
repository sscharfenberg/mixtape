<?php

namespace App\Models;

use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    // HasUuids: the users table uses a uuid primary key (see the create_users_table
    // migration), so Eloquent must generate a UUID on insert instead of relying on
    // auto-increment. TwoFactorAuthenticatable backs the two_factor_* columns that
    // already exist on the table; the 2FA feature itself is switched on later.
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Send the email verification notification — but only when the feature is
     * enabled (config/fortify.php). Overrides the framework default so we send
     * our own German VerifyEmailNotification instead of Laravel's built-in mail.
     * Invoked by the framework's SendEmailVerificationNotification listener when
     * the Registered event fires on sign-up.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the password reset notification.
     *
     * Overrides the framework default so we send our own German
     * PasswordResetLinkNotification instead of Laravel's built-in mail.
     * Invoked by Password::broker()->sendResetLink() (App\Http\Controllers\
     * Auth\ForgotController).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new PasswordResetLinkNotification($token));
    }
}
