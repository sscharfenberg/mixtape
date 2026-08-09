<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flash / Toast Language Lines
    |--------------------------------------------------------------------------
    |
    | Messages flashed to the session (message / type / duration) and surfaced
    | as toast notifications by the frontend ToastContainer.
    |
    */

    'login' => [
        'welcome' => 'Willkommen zurück, :name!',
    ],

    'logout' => 'Du wurdest abgemeldet.',

    'register' => [
        'success' => 'Registrierung erfolgreich. Wir haben dir eine E-Mail mit einem Link zur Bestätigung der E-Mail-Adresse geschickt.',
    ],

    'password' => [
        'updated' => 'Dein Passwort wurde geändert.',
    ],

    'profile' => [
        'updated' => 'Dein Profil wurde aktualisiert.',
        'updated_email' => 'Dein Profil wurde aktualisiert. Bitte bestätige deine neue E-Mail-Adresse — wir haben dir einen Link geschickt.',
    ],

    'account' => [
        'deleted' => 'Dein Benutzerkonto wurde gelöscht - du bist jederzeit willkommen zurückzukehren.',
    ],

    'email' => [
        'verified' => 'Deine E-Mail-Adresse wurde erfolgreich bestätigt.',
        'verification_resent' => 'Falls ein unbestätigtes Konto mit diesem Benutzernamen und dieser E-Mail-Adresse existiert, haben wir eine Bestätigungs-E-Mail gesendet.',
    ],

    'username' => [
        'reminder_sent' => 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir eine Benutzername-Erinnerung gesendet.',
    ],

    'two_factor' => [
        'activated' => 'Die Zwei-Faktor-Authentifizierung wurde aktiviert.',
        'deactivated' => 'Die Zwei-Faktor-Authentifizierung wurde deaktiviert.',
    ],

    'playlist' => [
        'created' => 'Die Wiedergabeliste ":name" wurde angelegt.',
        'updated' => 'Die Wiedergabeliste ":name" wurde gespeichert.',
        // Genau ein Titel wird benannt, mehrere werden gezählt — wer zwölf Titel hinzufügt,
        // weiß, welche es waren; wer einen hinzufügt, will ihn bestätigt bekommen.
        'track_added' => '":name" wurde zu ":playlist" hinzugefügt.',
        'tracks_added' => ':count Titel wurden zu ":playlist" hinzugefügt.',
        // Kein Fehler, sondern eine Antwort: die Wiedergabeliste enthält bereits alles davon.
        'tracks_already' => '":playlist" enthält bereits alle diese Titel.',
    ],

];
