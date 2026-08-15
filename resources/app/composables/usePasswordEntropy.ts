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

    /** The run in flight, so a newer question can cancel the one it supersedes. */
    let inFlight: AbortController | null = null;

    /**
     * Ask the server to score what is in the field, and paint the answer if it still applies.
     *
     * TWO RUNS ARE GENUINELY CONCURRENT HERE — the max-wait fires mid-typing and the trailing
     * 750ms run follows it — so the guards are not decoration. The previous request is aborted,
     * and the answer is compared with the password ON SCREEN before it is used: without that, a
     * slow score for "geheim" lands after a fresh one for "geheim123" and paints the meter with
     * the strength of a password the reader has already moved past. The same shape, and the
     * same reason, as `useLibrarySearch`.
     *
     * A failure says nothing. There is nothing a reader can do about a strength check that did
     * not answer, the field itself still validates on submit, and the meter simply keeps its
     * last state rather than flashing an error beside a password being typed.
     */
    const checkEntropy = (): void => {
        inFlight?.abort();
        inFlight = null;

        if (!password.value.length) {
            score.value = null;

            return;
        }

        const asked = password.value;
        const controller = new AbortController();
        inFlight = controller;

        fetch("/password/entropy", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrf
            },
            body: JSON.stringify({ p: asked }),
            signal: controller.signal
        })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                return response.json();
            })
            .then((data: { score: number }) => {
                if (asked !== password.value) return;

                score.value = data.score;
            })
            .catch(() => {
                // Aborted, offline, or a refusal — see the note above on staying quiet.
            });
    };

    // 750ms after the last keystroke, but at least every 5000ms while typing.
    const onPasswordChange = debounce(checkEntropy, 750, 5000);

    const reset = (): void => {
        onPasswordChange.cancel();
        // A run already on the wire would otherwise paint a score onto a field just emptied.
        inFlight?.abort();
        inFlight = null;
        password.value = "";
        score.value = null;
    };

    return { password, score, onPasswordChange, reset };
}
