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

    'type_invalid' => 'The --type option must be music, audiobook or any.',
    'tracks_invalid' => 'The --tracks option must be a positive integer.',
    'no_such_user' => 'No account named ":name".',
    'no_users' => 'There is no account yet for a playlist to belong to.',
    'user_label' => 'Who should own the playlist?',
    'library_empty' => 'The collection holds no matching tracks — run `php artisan app:update` first.',
    'description' => 'Created for testing (app:playlist).',
    'filled' => 'Added 1 track to “:name” for :user.|Added :count tracks to “:name” for :user.',
    'short' => 'Fewer than the :asked asked for — that is all the collection had.',

];
