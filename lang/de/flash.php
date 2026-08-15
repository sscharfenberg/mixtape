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
        // Benennt die Liste, weil diese Meldung auf der ÜBERSICHT gelesen wird: die Seite, die
        // gesagt hätte, um welche es geht, ist gerade verschwunden.
        'deleted' => 'Die Wiedergabeliste ":name" wurde gelöscht.',
        // Benennt den Titel aus demselben Grund wie eine Zeile darüber — die Zeile, die die
        // Frage "welcher?" beantwortet hätte, ist eben verschwunden — und die Liste, weil ein
        // Titel in mehreren stehen kann.
        'track_removed' => '":name" wurde aus ":playlist" entfernt.',
    ],

    'share' => [
        // Benennt den Link nicht: die Zeile, auf die er sich bezieht, ist mit dem Klick
        // verschwunden, und "welchen" hat der Dialog davor schon beantwortet.
        'revoked' => 'Der Link wurde zurückgezogen und funktioniert ab sofort nicht mehr.',
        // Nennt den Link ebenfalls nicht — der Dialog davor hat gesagt, um welchen es geht, und
        // die Zeile ist nach dem Klick in der oberen Liste zu sehen. Die Tage kommen aus
        // Share::LIFETIME_DAYS, damit hier keine Zahl steht, die die Regel überleben könnte.
        'renewed' => 'Der Link funktioniert wieder — für :days Tage ab jetzt.',
    ],

];
