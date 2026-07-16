<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    // MixTape authenticates by the account name (mirrors cantrip.me), not email.
    // 'email' still names the column used for password-reset lookups later on.
    'username' => 'name',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    // Names are case-preserving, so do not fold the login identifier to lowercase.
    'lowercase_usernames' => false,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    // Off: FortifyServiceProvider calls Fortify::ignoreRoutes(), so every route
    // (including the GET /login view) is declared explicitly in routes/web.php.
    'views' => false,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    'features' => [
        // Onboarding is invite-only: registration is ENABLED, but both the routes
        // (routes/web.auth.php) and the user-creation action
        // (App\Actions\Fortify\CreateNewUser) require a valid one-time invite code
        // (App\Models\Invite, minted via `php artisan app:invite`). There is no
        // open self-service signup — a valid invite link is mandatory.
        Features::registration(),
        //
        // Email verification IS enabled: registration creates an unverified user,
        // sends a verification email (App\Notifications\VerifyEmailNotification),
        // and login is blocked until the address is confirmed
        // (App\Actions\Fortify\EnsureEmailIsVerified). The "resend verification"
        // endpoint is intentionally NOT wired yet. Note: with MAIL_MAILER=log the
        // link lands in the log until a mail relay (Mailtrap) is configured.
        Features::emailVerification(),
        //
        // Password reset ("forgot password / username", App\Http\Controllers\Auth\
        // ForgotController + NewPasswordController) is enabled.
        Features::resetPasswords(),
        //
        // Deferred until the account / 2FA management UI lands. The DB columns and
        // the User model's TwoFactorAuthenticatable trait are already in place so
        // this becomes a one-line flip (plus its routes) when we build that screen.
        // Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        //
        // Features::updateProfileInformation(),
        // Features::updatePasswords(),
    ],

];
