<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming an account deletion with the current password (`DELETE /user/delete`).
 *
 * WHY A FORM REQUEST WORKS HERE, when the obvious reading says it cannot. This route's modal
 * drives it with `fetch()` rather than an Inertia visit, so it needs the validation failure as a
 * 422 JSON body — and a `ValidationException` renders as JSON only for the requests
 * `shouldRenderJsonWhen` accepts in bootstrap/app.php. That list includes `$request->wantsJson()`,
 * and useDeleteAccount.ts sends `Accept: application/json`, so the exception arrives as the 422 the
 * modal wants. A hand-rolled `Hash::check` plus a hand-built body would work and be the wrong
 * shape; if `shouldRenderJsonWhen` is ever narrowed, this is the route that silently starts
 * answering with a redirect.
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
