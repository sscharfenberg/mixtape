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
        'name.required' => 'Please give the playlist a name.',
        'name.max' => 'The name may not be longer than :max characters.',
        'name.unique' => 'You already have a playlist with this name.',
        'description.max' => 'The description may not be longer than :max characters.',
        'ids.incomplete' => 'The order must contain all of your playlists.',
        'tracks.incomplete' => 'The order must contain every track in the playlist.',
    ],

    'attributes' => [
        'name' => 'name',
        'description' => 'description',
    ],

];
