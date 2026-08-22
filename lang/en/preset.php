<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export Preset Language Lines
    |--------------------------------------------------------------------------
    |
    | Validation for the .m3u export preset form, passed to the FormRequest as
    | INLINE messages rather than added to validation.php's `custom` block. That
    | block is keyed by attribute name alone, and its `name` entry belongs to the
    | username — every auth form in the app posts a `name` and means the login id.
    | Without this file a duplicate preset name would answer "this username is
    | already taken".
    |
    | The keys are flat ("name.required"), which is what the inline-message lookup
    | matches on; a nested array would silently never be found.
    |
    */

    'validation' => [
        'name.required' => 'Please give the preset a name.',
        'name.max' => 'The name may not be longer than :max characters.',
        'name.unique' => 'You already have a preset with this name.',
        // Deliberately does not state the number: it lives in StoreExportPresetRequest::LIMIT,
        // and a copy of it here would outlive a change to that rule.
        'name.too_many' => 'You already have the maximum number of presets. Delete one to make room.',
        'format.required' => 'Please choose a file format.',
        'format.in' => 'Unknown file format.',
        'encoding.required' => 'Please choose a character encoding.',
        'encoding.in' => 'Unknown character encoding.',
        'path_prefix.max' => 'The path prefix may not be longer than :max characters.',
        'path_prefix.not_regex' => 'The path prefix may not contain a line break.',
    ],

    'attributes' => [
        'name' => 'Name',
        'format' => 'File format',
        'encoding' => 'Character encoding',
        'path_prefix' => 'Path prefix',
    ],

];
