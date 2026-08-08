<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Resend the verification email" (`POST /resend-verification`), for guests.
 *
 * Both name AND email are required, which is an anti-enumeration measure rather than a
 * convenience: matching on email alone would let a caller learn which addresses have
 * accounts. The controller then answers with the same generic success whatever it found.
 *
 * No `authorize()`: an unverified user cannot sign in, so this has to work for a guest.
 */
class ResendVerificationRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
