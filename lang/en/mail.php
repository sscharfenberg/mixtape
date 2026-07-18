<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification E-Mail Language Lines
    |--------------------------------------------------------------------------
    |
    | Content for the notification e-mails (App\Notifications\*). These resolve
    | in the recipient's locale, since User implements HasLocalePreference.
    |
    */

    'greeting' => 'Hello!',
    'salutation' => "Kind regards,\nThe MixTape team",

    'verify_email' => [
        'subject' => 'Confirm your email address',
        'line_1' => 'Please click the button below to confirm your email address.',
        'action' => 'Confirm email address',
        'line_2' => 'If you did not create an account, no further action is required.',
    ],

    'reset_password' => [
        'subject' => 'Reset password',
        'line_1' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset password',
        'line_expires' => 'This password reset link will expire in :count minutes.',
        'line_2' => 'If you did not request a password reset, no further action is required.',
    ],

    'forgot_username' => [
        'subject' => 'Your username',
        'line_1' => 'You are receiving this email because we received a request to remind you of your username.',
        'line_name' => 'Your username is: :name',
        'line_2' => 'If you did not make this request, no further action is required.',
    ],

];
