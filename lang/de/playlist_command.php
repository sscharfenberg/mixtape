<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Playlist Command Language Lines
    |--------------------------------------------------------------------------
    |
    | Console output for the `app:playlist` command (App\Console\Commands\
    | FillPlaylist). The CLI has no request locale, so it uses the app default.
    |
    | Its own file rather than a block in playlist.php: that one holds the form's
    | validation messages, which are keyed flat ("name.required") because the
    | inline-message resolver matches on exactly that shape — mixing prose keys
    | into it would invite a nested entry that is silently never found.
    |
    */

    'type_invalid' => 'Die Option --type muss music, audiobook oder any sein.',
    'tracks_invalid' => 'Die Option --tracks muss eine positive ganze Zahl sein.',
    'no_such_user' => 'Kein Konto mit dem Namen ":name".',
    'no_users' => 'Es gibt noch kein Konto, dem eine Wiedergabeliste gehören könnte.',
    'user_label' => 'Wem soll die Wiedergabeliste gehören?',
    'library_empty' => 'Die Sammlung enthält keine passenden Titel — erst `php artisan app:update` laufen lassen.',
    'description' => 'Zum Ausprobieren angelegt (app:playlist).',
    'filled' => '1 Titel zu „:name“ von :user hinzugefügt.|:count Titel zu „:name“ von :user hinzugefügt.',
    'short' => 'Weniger als die gewünschten :asked — so viele hat die Sammlung hergegeben.',

];
