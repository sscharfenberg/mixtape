<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming an account deletion with the current password (`DELETE /user/delete`).
 *
 * WHY THIS IS A FORM REQUEST AGAIN. It used to be a hand-rolled `Hash::check` plus a
 * hand-built 422 JSON body, and the controller's docblock justified that: bootstrap/app.php
 * rendered validation exceptions as JSON only for `api/*` and Precognition, so the
 * exception this route's fetch()-based modal depends on would have come back as a redirect.
 * That was TRUE WHEN WRITTEN and stopped being true in a5e6659, which added
 * `$request->wantsJson()` to `shouldRenderJsonWhen` for the 2FA flows — and
 * useDeleteAccount.ts sends `Accept: application/json`. So the exception now renders as the
 * 422 the modal wants, and the manual check was working around a condition that no longer
 * held. (Worth remembering as a shape: a comment can be correct and still rot, and this one
 * rotted in a file it does not live in.)
 *
 * `current_password` rather than a hash comparison of our own: it is the framework's rule
 * for exactly this, it reads against the default guard — `web`, the same one
 * `config('fortify.guard')` names — and it cannot be got subtly wrong the way a hand-written
 * comparison can (a `==`, a missing empty-string guard).
 *
 * No `authorize()`: the route is behind `auth` and a user may always delete THEIR OWN
 * account. There is no other subject — the controller deletes `$request->user()`, so a
 * caller cannot name anybody else.
 */
class DeleteAccountRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password'],
        ];
    }

    /**
     * Both failures keep the copy the manual check used, so this refactor changes no
     * user-visible text.
     *
     * The `required` branch is unreachable from the UI — AccountDeleteModal disables its
     * submit while the field is empty — so it exists for a hand-crafted request, and there
     * is no reason for that to get a different answer than a wrong password does.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => __('auth.password_incorrect'),
            'password.current_password' => __('auth.password_incorrect'),
        ];
    }
}
