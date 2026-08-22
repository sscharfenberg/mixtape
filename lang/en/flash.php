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
        'welcome' => 'Welcome back, :name!',
    ],

    'logout' => 'You have been logged out.',

    'register' => [
        'success' => 'Registration successful. We have sent you an email with a link to confirm your email address.',
    ],

    'password' => [
        'updated' => 'Your password has been changed.',
    ],

    'profile' => [
        'updated' => 'Your profile has been updated.',
        'updated_email' => 'Your profile has been updated. Please confirm your new email address — we have sent you a link.',
    ],

    'account' => [
        'deleted' => 'Your account has been deleted - you are welcome back any time.',
    ],

    'email' => [
        'verified' => 'Your email address has been verified successfully.',
        'verification_resent' => 'If an unverified account with this username and email address exists, we have sent a verification email.',
    ],

    'username' => [
        'reminder_sent' => 'If an account with that email address exists, we have sent a username reminder.',
    ],

    'two_factor' => [
        'activated' => 'Two-factor authentication has been activated.',
        'deactivated' => 'Two-factor authentication has been deactivated.',
    ],

    'playlist' => [
        'created' => 'The playlist ":name" has been created.',
        'updated' => 'The playlist ":name" has been saved.',
        // Exactly one track is named, several are counted — somebody adding twelve knows what
        // they were; somebody adding one wants it confirmed back to them.
        'track_added' => '":name" was added to ":playlist".',
        'tracks_added' => ':count tracks were added to ":playlist".',
        // Not a failure but an answer: the playlist already holds all of it.
        'tracks_already' => '":playlist" already holds all of those tracks.',
        // Names the playlist, because this message is read on the LISTING: the page that
        // would have said which one is the page that has just gone.
        'deleted' => 'The playlist ":name" has been deleted.',
        // Names the track for the same reason one row up — the row that would have answered
        // "which one?" is the row that just disappeared — and the playlist because a track
        // can sit in several.
        'track_removed' => '":name" was removed from ":playlist".',
    ],

    'preset' => [
        'created' => 'The export preset ":name" has been created.',
        'updated' => 'The export preset ":name" has been saved.',
        'deleted' => 'The export preset ":name" has been deleted.',
        // Names the preset, because all that moves in the list is a marker — without the name
        // it would be unclear which row took the click.
        'default' => '":name" is now the preset the export dialog opens with.',
    ],

    'share' => [
        // Deliberately does not name the link: the row it refers to went away with the click,
        // and "which one" was answered by the dialog just before it.
        'revoked' => 'The link has been revoked and no longer works.',
        // Names no link either: the dialog just before it said which one, and the row is now in
        // the list above. The days come from Share::LIFETIME_DAYS so no number here can outlive
        // the rule.
        'renewed' => 'The link works again — for :days days from now.',
    ],

];
