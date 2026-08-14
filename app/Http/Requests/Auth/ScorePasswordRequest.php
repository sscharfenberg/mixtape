<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The password the strength meter wants scored (`POST /password/entropy`).
 *
 * THE `max` IS THE POINT OF THIS CLASS, not tidiness. The route is unauthenticated and allows
 * 60 requests a minute per IP, and zxcvbn's matching is super-linear in the length of what it is
 * given — so without a ceiling the body limit alone decides the work, and a megabyte of text
 * sixty times a minute is cheap CPU exhaustion on a box that is deliberately internet-facing.
 * 255 is the same bound the registration form's own rules carry, so the meter cannot be asked
 * about a password that could never be stored.
 *
 * Open to guests by design: the meter runs on the register and reset forms, where by definition
 * nobody is signed in yet.
 */
class ScorePasswordRequest extends FormRequest
{
    /** No subject to guard — the route group is the whole of the access decision. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['p' => ['required', 'string', 'max:255']];
    }
}
