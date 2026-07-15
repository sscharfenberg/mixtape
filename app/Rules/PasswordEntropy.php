<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ZxcvbnPhp\Zxcvbn;

/**
 * Rejects weak passwords using zxcvbn's real-world strength estimate (ported
 * from cantrip.me). zxcvbn scores 0–4 by modelling actual guessing attacks
 * (dictionaries, patterns, l33t, keyboard walks) rather than naive character-
 * class rules; we require **score ≥ 3** ("safely unguessable" against an offline
 * slow-hash attack). The same library backs the live strength meter
 * (App\Http\Controllers\Auth\EntropyController), so the meter and this gate agree.
 */
class PasswordEntropy implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Emptiness / non-strings are reported by the 'required'/'string' rules;
        // don't double up with a strength error here.
        if (! is_string($value) || $value === '') {
            return;
        }

        if ((new Zxcvbn)->passwordStrength($value)['score'] < 3) {
            $fail('Das Passwort ist nicht sicher genug.');
        }
    }
}
