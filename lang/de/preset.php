<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export Preset Language Lines
    |--------------------------------------------------------------------------
    |
    | Validation für das Formular der .m3u-Export-Voreinstellungen, INLINE an den
    | FormRequest übergeben statt in den `custom`-Block von validation.php gelegt.
    | Der Block ist allein nach Feldnamen geschlüsselt, und sein `name`-Eintrag
    | gehört dem BENUTZERNAMEN — jedes Auth-Formular der App sendet ein `name` und
    | meint die Login-Kennung. Ohne diese Datei würde ein doppelter Name hier mit
    | "dieser Benutzername ist bereits vergeben" beantwortet.
    |
    | Die Schlüssel sind flach ("name.required"), worauf die Inline-Suche matcht;
    | ein verschachteltes Array würde stillschweigend nie gefunden.
    |
    */

    'validation' => [
        'name.required' => 'Bitte gib der Voreinstellung einen Namen.',
        'name.max' => 'Der Name darf höchstens :max Zeichen enthalten.',
        'name.unique' => 'Du hast bereits eine Voreinstellung mit diesem Namen.',
        // Nennt die Zahl nicht: sie steht in StoreExportPresetRequest::LIMIT, und eine hier
        // wiederholte Zahl überlebt deren Änderung.
        'name.too_many' => 'Du hast bereits die maximale Anzahl an Voreinstellungen. Lösche eine, um Platz zu schaffen.',
        'format.required' => 'Bitte wähle ein Dateiformat.',
        'format.in' => 'Unbekanntes Dateiformat.',
        'encoding.required' => 'Bitte wähle eine Zeichenkodierung.',
        'encoding.in' => 'Unbekannte Zeichenkodierung.',
        'path_prefix.max' => 'Das Pfad-Präfix darf höchstens :max Zeichen enthalten.',
        'path_prefix.not_regex' => 'Das Pfad-Präfix darf keinen Zeilenumbruch enthalten.',
    ],

    'attributes' => [
        'name' => 'Name',
        'format' => 'Dateiformat',
        'encoding' => 'Zeichenkodierung',
        'path_prefix' => 'Pfad-Präfix',
    ],

];
