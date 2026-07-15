import { usePage } from "@inertiajs/vue3";
import { type Ref, ref } from "vue";
import { debounce } from "Utils/debounce";

/**
 * Real-time password strength (ported from cantrip.me).
 *
 * Posts the current password to the server-side zxcvbn endpoint
 * (`/password/entropy`) and exposes a reactive score (0–4, or `null` before the
 * first response). The POST is debounced (react shortly after the user stops
 * typing) with a max-wait (so a non-stop typer still gets feedback). Server-
 * scored on purpose, so the meter matches the PasswordEntropy validation gate
 * exactly. Bind `password` with v-model and call `onPasswordChange` on keyup.
 */
export function usePasswordEntropy(): {
    password: Ref<string>;
    score: Ref<number | null>;
    onPasswordChange: () => void;
    reset: () => void;
} {
    const password = ref("");
    const score = ref<number | null>(null);
    // Shared by HandleInertiaRequests; sent so the POST clears CSRF on the web route.
    const csrf = String(usePage().props.csrfToken ?? "");

    const checkEntropy = (): void => {
        if (!password.value.length) {
            score.value = null;
            return;
        }
        fetch("/password/entropy", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrf
            },
            body: JSON.stringify({ p: password.value })
        })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(data => {
                score.value = data.score;
            })
            .catch(error => {
                console.error(error);
            });
    };

    // 750ms after the last keystroke, but at least every 5000ms while typing.
    const onPasswordChange = debounce(checkEntropy, 750, 5000);

    const reset = (): void => {
        onPasswordChange.cancel();
        password.value = "";
        score.value = null;
    };

    return { password, score, onPasswordChange, reset };
}
