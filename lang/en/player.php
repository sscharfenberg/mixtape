<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Player Language Lines
    |--------------------------------------------------------------------------
    |
    | Validation for the play queue's own endpoints, passed as INLINE messages
    | for the reason the playlist catalogue beside this one gives: the `custom`
    | block in validation.php is keyed by attribute name alone, and `ids` is
    | already the playlist reorder's attribute — a message added there would
    | answer for both.
    |
    | The keys are flat ("selection.too_large"), which is what the inline-message
    | lookup matches on; a nested array would silently never be found.
    |
    */

    'validation' => [
        'selection.too_large' => 'That selection holds more tracks than the queue can take.',
    ],

];
