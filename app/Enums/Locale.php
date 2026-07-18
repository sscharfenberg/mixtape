<?php

namespace App\Enums;

/**
 * The set of locales MixTape supports (ported from cantrip.me's Locale enum).
 *
 * Single source of truth for "which languages exist": the ConfigureLocale
 * middleware validates browser/session/DB values against it, LocaleController
 * rejects anything not a case, HandleInertiaRequests shares the case list to the
 * frontend, and the User model casts `users.locale` to it. `De` is the default
 * (config/app.php → 'locale').
 */
enum Locale: string
{
    case De = 'de';
    case En = 'en';

    /**
     * The bare string values of every supported locale, e.g. ['de', 'en'].
     *
     * Shared with the frontend (HandleInertiaRequests) so the language switcher
     * can render one entry per locale without hardcoding the list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
