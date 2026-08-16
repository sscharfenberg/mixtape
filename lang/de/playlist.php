<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Playlist Language Lines
    |--------------------------------------------------------------------------
    |
    | Validation for the "new playlist" form, passed to $request->validate() as
    | INLINE messages rather than added to validation.php's `custom` block. That
    | block is keyed by attribute name alone, and its `name` entry belongs to the
    | username — every auth form in the app posts a `name` and means the login id.
    | Inline messages beat `custom` in the resolver, so these apply here only.
    |
    | The keys are flat ("name.required"), which is what the inline-message lookup
    | matches on; a nested array would silently never be found.
    |
    */

    'validation' => [
        'name.required' => 'Bitte gib der Wiedergabeliste einen Namen.',
        'name.max' => 'Der Name darf höchstens :max Zeichen enthalten.',
        'name.unique' => 'Du hast bereits eine Wiedergabeliste mit diesem Namen.',
        'description.max' => 'Die Beschreibung darf höchstens :max Zeichen enthalten.',
        'ids.incomplete' => 'Die Reihenfolge muss alle deine Wiedergabelisten enthalten.',
        'ids.too_many_tracks' => 'Diese Auswahl enthält mehr Titel, als auf einmal hinzugefügt werden können.',
        'tracks.incomplete' => 'Die Reihenfolge muss alle Titel der Wiedergabeliste enthalten.',
    ],

    'export' => [
        'format.in' => 'Unbekanntes Dateiformat.',
        'encoding.in' => 'Unbekannte Zeichenkodierung.',
        'prefix.max' => 'Das Pfad-Präfix darf höchstens :max Zeichen enthalten.',
        'prefix.not_regex' => 'Das Pfad-Präfix darf keinen Zeilenumbruch enthalten.',
    ],

    'attributes' => [
        'name' => 'Name',
        'description' => 'Beschreibung',
    ],

];
