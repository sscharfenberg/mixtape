<?php

namespace App\Models;

use App\Enums\Locale;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'locale', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    // HasUuids: the users table uses a uuid primary key (see the create_users_table
    // migration), so Eloquent must generate a UUID on insert instead of relying on
    // auto-increment. TwoFactorAuthenticatable backs the two_factor_* columns on the
    // table and powers the 2FA feature (config/fortify.php → twoFactorAuthentication).
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
            'locale' => Locale::class,
        ];
    }

    /**
     * The user's preferred locale, honoured by Laravel when localizing queued
     * mail / notifications (Illuminate\Contracts\Translation\HasLocalePreference),
     * so a verification or password-reset email goes out in their language
     * regardless of the request that triggered it.
     */
    public function preferredLocale(): string
    {
        return $this->locale?->value ?? config('app.locale');
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

    /** The user's saved playlists. @return HasMany<Playlist, $this> */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    /** The user's listen history. @return HasMany<Play, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /** The user's persisted play queue (1:1). @return HasOne<PlayerState, $this> */
    public function playerState(): HasOne
    {
        return $this->hasOne(PlayerState::class);
    }
}
