<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Forgot password / username" (`POST /forgot`), for guests.
 *
 * `type` picks which recovery is being asked for, and is what makes `name` conditional:
 * a password reset requires the username as well as the email — an extra check beyond
 * Fortify's own broker — while a username reminder has only the email to go on.
 *
 * No `authorize()`: the route is in the `guest` group and the whole point is that nobody
 * is signed in. The controller flashes the same success message either way, so a caller
 * learns nothing here about which accounts exist.
 */
class ForgotRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:password,name'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required_if:type,password', 'string', 'min:3', 'max:80'],
        ];
    }
}
