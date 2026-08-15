import { router, usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";
import type { Composer } from "vue-i18n";
import { getI18n } from "@/i18n";

/**
 * Translate a key via the global i18n instance. These error strings are pushed
 * from async fetch handlers — outside component setup, where `useI18n()` isn't
 * available — so we reach the singleton directly; the cast bridges vue-i18n's
 * `Composer | VueI18n` union to a callable `t`.
 */
const translate = (key: string): string => (getI18n().global as unknown as Composer).t(key);

/**
 * The three flags a page must send for the 2FA panel to know what to draw.
 *
 * `DashboardController` is the only thing that sends them, and they are PAGE data rather than
 * shared data: whether this reader has 2FA on, and whether the Fortify features that gate
 * enrollment are enabled. Named here, beside the composable that reads them, so a page adding
 * the panel has one place to look for what it owes.
 */
export type TwoFactorPageProps = {
    /** Whether this reader has completed enrollment. */
    twoFactorEnabled: boolean;
    /** Fortify's `confirm` option — enrollment ends with a code rather than at the QR step. */
    requiresConfirmation: boolean;
    /** Fortify's `confirmPassword` option — a sensitive action asks for the password first. */
    requiresPasswordConfirmation: boolean;
};

/**
 * Return type of the {@link useTwoFactorAuth} composable.
 *
 * MOST OF THIS IS SHARED, and which parts are is the thing to know before using it: every
 * `Ref` below except `processing` is MODULE state, so two components calling the composable
 * see one QR code, one set of recovery codes and one error list. That is deliberate — the
 * panel and the modal are two views of one enrollment — and it is why signing out has to
 * forget it (see `forgetTwoFactorState`). `processing` is per-consumer, so one form's spinner
 * does not disable another's button.
 */
export type UseTwoFactorAuthReturn = {
    /** The enrollment QR as raw SVG, or null before it is fetched or after a failure. */
    qrCodeSvg: Ref<string | null>;
    /** The TOTP secret in typeable form, for an authenticator that cannot scan. */
    manualSetupKey: Ref<string | null>;
    /** The one-time backup codes. Populated only while the reader has asked to see them. */
    recoveryCodesList: Ref<string[]>;
    /** Messages for things that went wrong out of band — a failed fetch, a refused action. */
    errors: Ref<string[]>;
    /** Field-keyed messages from a rejected form, e.g. a wrong confirmation code. */
    validationErrors: Ref<Record<string, string>>;
    /** PER CONSUMER, not shared: this form's request is in flight. */
    processing: Ref<boolean>;
    /** Whether the codes are on screen — the flag that renders them without a re-fetch. */
    isRecoveryCodesVisible: Ref<boolean>;
    /** Whether the enrollment modal is open. */
    showSetupModal: Ref<boolean>;
    /** Fortify's `confirm` option: enrollment ends with a code rather than at the QR step. */
    requiresConfirmation: ComputedRef<boolean>;
    /** Fortify's `confirmPassword` option: a sensitive action asks for the password first. */
    requiresPasswordConfirmation: ComputedRef<boolean>;
    /** Whether this reader has completed enrollment. */
    twoFactorEnabled: ComputedRef<boolean>;
    /** Both halves of the setup screen have arrived, so it can be drawn. */
    hasSetupData: ComputedRef<boolean>;
    /** Forget the QR and key — for a cancelled enrollment, so a reopen starts clean. */
    clearSetupData: () => void;
    /** Empty the out-of-band error list. */
    clearErrors: () => void;
    /** Forget everything, for when 2FA has been disabled outright. */
    clearTwoFactorAuthData: () => void;
    /** Confirm the password against Fortify, so the next sensitive call is allowed through. */
    confirmPassword: (pw: string) => Promise<boolean>;
    /** Begin enrollment; the QR and key follow. */
    enableTwoFactor: (pw: string) => Promise<void>;
    /** End enrollment and forget everything it produced. */
    disableTwoFactor: (pw: string) => Promise<void>;
    /** Reveal the existing recovery codes, asking for a password first when Fortify wants one. */
    handleShowRecoveryCodes: (pw: string) => Promise<void>;
    /** Mint a fresh set, retrying once if the session's password confirmation has expired. */
    handleRegenerateRecoveryCodes: (pw: string) => Promise<void>;
    /** Fetch the QR alone. Reports its own failure rather than throwing. */
    fetchQrCode: () => Promise<void>;
    /** Fetch the manual key alone. Reports its own failure rather than throwing. */
    fetchSetupKey: () => Promise<void>;
    /** Both of the above together, which is what the setup screen needs. */
    fetchSetupData: () => Promise<void>;
    /** Fetch the recovery codes. */
    fetchRecoveryCodes: () => Promise<void>;
};

/**
 * Perform a JSON GET request against the given URL.
 *
 * Thin wrapper around `fetch` that sets the `Accept` header,
 * checks for a successful response status, and parses the body as JSON.
 *
 * @template T - The expected shape of the JSON response body.
 * @param url - The endpoint to request.
 * @returns The parsed JSON response.
 * @throws {Error} When the response status is not OK.
 */
const fetchJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: { Accept: "application/json" }
    });

    if (!response.ok) {
        throw new Error(`Failed to fetch: ${response.status}`);
    }

    // The cast is the honest one: `json()` is `any`, and the caller names the shape it expects.
    // Nothing here can verify that, so it is stated rather than implied.
    return (await response.json()) as T;
};

// Shared reactive state — declared outside the composable so that every
// component calling `useTwoFactorAuth()` operates on the same data.
const errors = ref<string[]>([]);
const validationErrors = ref<Record<string, string>>({});
const manualSetupKey = ref<string | null>(null);
const qrCodeSvg = ref<string | null>(null);
const recoveryCodesList = ref<string[]>([]);
const isRecoveryCodesVisible = ref(false);
const showSetupModal = ref(false);

/** Whether both the QR code and manual setup key have been loaded. */
const hasSetupData = computed<boolean>(() => qrCodeSvg.value !== null && manualSetupKey.value !== null);

/**
 * Drop every piece of 2FA state this module is holding.
 *
 * EXPORTED BECAUSE SIGNING OUT MUST CALL IT, and nothing else can. Logging out is an Inertia
 * visit under a layout that never unmounts, so `setup()` does not run again and everything
 * above — including `recoveryCodesList`, which holds codes a reader has just revealed, and
 * `isRecoveryCodesVisible`, which makes the panel render them on mount without a fetch —
 * would otherwise still be here for whoever signs in next on a shared browser. That is the
 * same hazard `abandonQueue` exists for, and it is called from the same watcher.
 *
 * Reset state, never a request: this runs at the moment the session ends, when a fetch to
 * Fortify would be answered 401 or, worse, by the next reader's session.
 */
export const forgetTwoFactorState = (): void => {
    errors.value = [];
    validationErrors.value = {};
    manualSetupKey.value = null;
    qrCodeSvg.value = null;
    recoveryCodesList.value = [];
    isRecoveryCodesVisible.value = false;
    showSetupModal.value = false;
};

/**
 * Composable that manages two-factor authentication setup and recovery codes
 * (ported from cantrip.me).
 *
 * All reactive state (QR code, setup key, recovery codes, errors) is shared
 * across every consumer so that multiple components can read/write the same
 * 2FA state without prop-drilling.
 *
 * Interacts with Fortify's built-in 2FA endpoints:
 * - `GET /user/two-factor-qr-code` — SVG of the TOTP QR code.
 * - `GET /user/two-factor-secret-key` — manual entry key for authenticator apps.
 * - `GET /user/two-factor-recovery-codes` — one-time-use backup codes.
 */
export const useTwoFactorAuth = (): UseTwoFactorAuthReturn => {
    // Per-component — each consumer gets its own processing state so that
    // submitting one form does not disable buttons in sibling forms.
    const processing = ref(false);

    /**
     * Fetch the TOTP QR code SVG from Fortify.
     *
     * The SVG can be rendered directly in the template so the user can scan
     * it with their authenticator app. On failure the QR code ref is cleared
     * and an error message is recorded.
     */
    const fetchQrCode = async (): Promise<void> => {
        try {
            const { svg } = await fetchJson<{ svg: string; url: string }>("/user/two-factor-qr-code");

            qrCodeSvg.value = svg;
        } catch {
            errors.value.push(translate("dashboard.twoFactor.errors.qrCode"));
            qrCodeSvg.value = null;
        }
    };

    /**
     * Fetch the manual setup key from Fortify.
     *
     * This is the base-32 secret that users can type into their authenticator
     * app when scanning a QR code is not possible.
     */
    const fetchSetupKey = async (): Promise<void> => {
        try {
            const { secretKey: key } = await fetchJson<{ secretKey: string }>("/user/two-factor-secret-key");

            manualSetupKey.value = key;
        } catch {
            errors.value.push(translate("dashboard.twoFactor.errors.setupKey"));
            manualSetupKey.value = null;
        }
    };

    /**
     * Reset the QR code and manual setup key refs and clear any errors.
     *
     * Useful when the user cancels the 2FA enrollment flow and the
     * setup UI needs to return to its initial state.
     */
    const clearSetupData = (): void => {
        manualSetupKey.value = null;
        qrCodeSvg.value = null;
        clearErrors();
    };

    /** Clear all recorded error messages. */
    const clearErrors = (): void => {
        errors.value = [];
    };

    /**
     * Reset every piece of 2FA state (setup data, recovery codes, errors).
     *
     * Intended to be called after 2FA has been fully disabled so the UI
     * no longer displays stale data from a previous enrollment.
     */
    const clearTwoFactorAuthData = (): void => forgetTwoFactorState();

    /**
     * Fetch the current set of one-time-use recovery codes from Fortify.
     *
     * Recovery codes let the user regain access to their account if they
     * lose their authenticator device. The codes should be displayed once
     * and stored securely by the user.
     */
    const fetchRecoveryCodes = async (): Promise<void> => {
        try {
            clearErrors();
            recoveryCodesList.value = await fetchJson<string[]>("/user/two-factor-recovery-codes");
        } catch {
            errors.value.push(translate("dashboard.twoFactor.errors.recoveryCodesLoad"));
            recoveryCodesList.value = [];
        }
    };

    /**
     * Fetch both the QR code and the manual setup key in parallel.
     *
     * Convenience wrapper used during initial 2FA enrollment to load all
     * data the setup screen needs in a single call.
     */
    const fetchSetupData = async (): Promise<void> => {
        /*
         * NO try/catch, because there is nothing here that can reject. Both calls handle their
         * own failure — each pushes its own message and nulls its own ref — so a `Promise.all`
         * over them always resolves, and a catch around it is dead code that reads like a
         * safety net. If either is ever changed to rethrow, the handling belongs where the
         * message is chosen, not here where it could only say something vaguer.
         */
        clearErrors();
        await Promise.all([fetchQrCode(), fetchSetupKey()]);
    };

    /*
     * THE THREE FLAGS ARE PAGE PROPS, NOT SHARED ONES, so they are declared as a page shape
     * rather than added to the app-wide augmentation: only DashboardController sends them, and
     * putting them in `sharedPageProps` would claim every response in the app carries them.
     *
     * Typed through `usePage`'s generic rather than cast. `as boolean` on an absent prop
     * produces `undefined` wearing a boolean's type, which then reads as false everywhere
     * except a strict comparison — so a page that forgot to send one would render the
     * "two-factor is off" branch rather than failing. `Partial` plus an explicit `?? false`
     * says the same thing out loud and means the type matches what actually arrives.
     */
    const page = usePage<Partial<TwoFactorPageProps>>();
    const requiresConfirmation = computed(() => page.props.requiresConfirmation ?? false);
    const requiresPasswordConfirmation = computed(() => page.props.requiresPasswordConfirmation ?? false);
    const twoFactorEnabled = computed(() => page.props.twoFactorEnabled ?? false);

    /**
     * Validate the user's password against the backend and mark it as confirmed
     * in the session. Uses a plain `fetch` (not Inertia) because this is a
     * side-effect-only API call — we need to set `auth.password_confirmed_at`
     * in the session so that Fortify's `password.confirm` middleware passes on
     * the subsequent 2FA request, without triggering an Inertia page visit.
     *
     * @returns `true` when the password was accepted, `false` on validation failure.
     */
    const confirmPassword = async (pw: string): Promise<boolean> => {
        const response = await fetch("/confirm-password", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string
            },
            body: JSON.stringify({ password: pw })
        });
        if (!response.ok) {
            const data = await response.json();
            validationErrors.value = Object.fromEntries(
                Object.entries(data.errors ?? {}).map(([key, msgs]) => [key, Array.isArray(msgs) ? msgs[0] : msgs])
            );
            return false;
        }
        return true;
    };

    /**
     * Orchestrates the two-factor authentication enable flow.
     *
     * When `confirmPassword` is enabled in the Fortify config, the user's
     * password is validated first via {@link confirmPassword} to satisfy
     * Fortify's `password.confirm` middleware. Once confirmed (or skipped
     * when not required), an Inertia POST to Fortify's 2FA endpoint enables
     * TOTP for the authenticated user and opens the setup modal on success.
     */
    const enableTwoFactor = async (pw: string): Promise<void> => {
        processing.value = true;
        validationErrors.value = {};

        if (requiresPasswordConfirmation.value) {
            const confirmed = await confirmPassword(pw);
            if (!confirmed) {
                processing.value = false;
                return;
            }
        }

        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    showSetupModal.value = true;
                },
                onFinish: () => {
                    processing.value = false;
                }
            }
        );
    };

    /**
     * Orchestrates the two-factor authentication disable flow.
     *
     * Mirrors {@link enableTwoFactor}: when `confirmPassword` is active in the
     * Fortify config, the user's password is validated first via
     * {@link confirmPassword} so Fortify's `password.confirm` middleware passes
     * on the subsequent DELETE request. Using `router.delete` (not Inertia's
     * `<Form>`) avoids the 405 that occurs when Fortify's middleware tries to
     * redirect to the GET `password.confirm` route before the session is set.
     */
    const disableTwoFactor = async (pw: string): Promise<void> => {
        processing.value = true;
        validationErrors.value = {};
        showSetupModal.value = false;

        if (requiresPasswordConfirmation.value) {
            const confirmed = await confirmPassword(pw);
            if (!confirmed) {
                processing.value = false;
                return;
            }
        }

        router.delete("/user/two-factor-authentication", {
            preserveScroll: true,
            onSuccess: () => {
                clearTwoFactorAuthData();
            },
            onFinish: () => {
                processing.value = false;
            }
        });
    };

    /**
     * Fetch and reveal the current recovery codes for the authenticated user.
     *
     * Mirrors the same password-confirmation flow used by enabling 2FA: when
     * `confirmPassword` is active, this first confirms the password to satisfy
     * Fortify's `password.confirm` middleware, then requests recovery codes.
     * On success, recovery codes are marked as visible in the UI.
     */
    const handleShowRecoveryCodes = async (pw: string): Promise<void> => {
        processing.value = true;
        validationErrors.value = {};

        if (requiresPasswordConfirmation.value) {
            const confirmed = await confirmPassword(pw);
            if (!confirmed) {
                processing.value = false;
                return;
            }
        }

        await fetchRecoveryCodes();
        isRecoveryCodesVisible.value = recoveryCodesList.value.length > 0;
        processing.value = false;
    };

    /**
     * Generate a fresh set of recovery codes and refresh the displayed list.
     *
     * Uses the same password-confirmation guard as other 2FA-sensitive actions.
     * The regenerate endpoint is called as JSON to avoid Fortify's default
     * redirect response, then recovery codes are fetched again for display.
     */
    const handleRegenerateRecoveryCodes = async (pw: string): Promise<void> => {
        processing.value = true;
        validationErrors.value = {};
        const postRegenerate = async (): Promise<Response> =>
            fetch("/user/two-factor-recovery-codes", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": page.props.csrfToken as string
                },
                body: JSON.stringify({})
            });

        let response = await postRegenerate();

        // Only ask for password again when the session confirmation has expired.
        if (response.status === 423 && requiresPasswordConfirmation.value) {
            const confirmed = await confirmPassword(pw);
            if (!confirmed) {
                processing.value = false;
                return;
            }
            response = await postRegenerate();
        }

        if (!response.ok) {
            errors.value.push(translate("dashboard.twoFactor.errors.recoveryCodesRegenerate"));
            processing.value = false;
            return;
        }

        await fetchRecoveryCodes();
        isRecoveryCodesVisible.value = recoveryCodesList.value.length > 0;
        processing.value = false;
    };

    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        errors,
        validationErrors,
        processing,
        isRecoveryCodesVisible,
        showSetupModal,
        requiresConfirmation,
        requiresPasswordConfirmation,
        twoFactorEnabled,
        hasSetupData,
        clearSetupData,
        clearErrors,
        clearTwoFactorAuthData,
        confirmPassword,
        enableTwoFactor,
        disableTwoFactor,
        handleShowRecoveryCodes,
        handleRegenerateRecoveryCodes,
        fetchQrCode,
        fetchSetupKey,
        fetchSetupData,
        fetchRecoveryCodes
    };
};
