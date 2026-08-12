<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Card Language Lines
    |--------------------------------------------------------------------------
    |
    | The Open Graph card — what a link to this app looks like pasted into a
    | chat window (App\Services\Meta\SocialCards). Server-side rather than in
    | the client catalog because unfurl crawlers never run the Vue app.
    |
    | The locale here is whatever ConfigureLocale resolved, which for a crawler
    | is the app default: they rarely send Accept-Language.
    |
    */

    'share' => [
        'title' => 'MixTape share: :name',
        'kind' => [
            'song' => 'Song',
            'album' => 'Album',
            'artist' => 'Artist',
        ],
        'songs' => ':count track|:count tracks',
        'minutes' => ':count minute|:count minutes',
        // NOT ":hours:minutes" — Laravel replaces the LONGER placeholder first, so the two
        // run together into nonsense ("1:12" comes out as "112"). Keep a word between them.
        'hours' => ':hours hr :minutes min',
        'expired' => 'This link has expired.',
    ],

    'invite' => [
        'title' => 'An invitation to MixTape',
        // Deliberately says nothing about WHO invited you or what the note said —
        // see SocialCards::invite() for why.
        'description' => 'You have been invited to a private music collection.',
    ],

    'site' => [
        'description' => 'A private music collection.',
    ],

];
