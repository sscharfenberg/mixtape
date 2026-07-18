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

    'greeting' => 'Hallo!',
    'salutation' => "Mit freundlichen Grüßen,\nDas Team von MixTape",

    'verify_email' => [
        'subject' => 'E-Mail-Adresse bestätigen',
        'line_1' => 'Bitte klicke auf die Schaltfläche, um deine E-Mail-Adresse zu bestätigen.',
        'action' => 'E-Mail-Adresse bestätigen',
        'line_2' => 'Wenn du kein Benutzerkonto erstellt hast, ist keine weitere Aktion erforderlich.',
    ],

    'reset_password' => [
        'subject' => 'Passwort zurücksetzen',
        'line_1' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passwortes für dein Konto erhalten haben.',
        'action' => 'Passwort zurücksetzen',
        'line_expires' => 'Dieser Link zum Zurücksetzen des Passwortes läuft in :count Minuten ab.',
        'line_2' => 'Wenn du kein Zurücksetzen des Passwortes angefordert hast, ist keine weitere Aktion erforderlich.',
    ],

    'forgot_username' => [
        'subject' => 'Ihr Benutzername',
        'line_1' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zur Erinnerung an deinen Benutzernamen erhalten haben.',
        'line_name' => 'Dein Benutzername lautet: :name',
        'line_2' => 'Wenn du diese Anfrage nicht gestellt hast, ist keine weitere Aktion erforderlich.',
    ],

];
