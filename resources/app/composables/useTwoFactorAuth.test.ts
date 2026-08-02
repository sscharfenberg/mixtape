import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { setupI18n } from "@/i18n";
import de from "@/lang/de.json";
import { useTwoFactorAuth } from "Composables/useTwoFactorAuth";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { translate } from "Testing/mount";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The 2FA lifecycle: enable → scan → confirm → recovery codes → disable. All of its
 * state is a module singleton shared by TwoFactorModal, TwoFactorEnabled and friends.
 *
 * Two things make it worth testing at this level rather than end to end. First, the
 * password-confirmation guard wraps EVERY sensitive action, and each one must abort
 * cleanly (leaving no spinner running) when the password is rejected. Second, the
 * regenerate path has a retry: Fortify answers 423 when the session's confirmation has
 * expired, and only then may the user be asked for a password again. That branch is
 * near-impossible to trigger by hand.
 *
 * Note this composable translates via the i18n SINGLETON (getI18n) rather than
 * useI18n(), because its error strings are pushed from async handlers outside setup —
 * so the singleton has to exist before any of it runs.
 */

/** Queue a sequence of fetch responses, consumed in order. */
const mockFetchSequence = (responses: { status: number; body?: unknown }[]) => {
    const fetchMock = vi.fn();
    responses.forEach(({ status, body }) => {
        fetchMock.mockResolvedValueOnce({
            ok: status >= 200 && status < 300,
            status,
            json: () => Promise.resolve(body ?? {})
        });
    });
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

describe("useTwoFactorAuth", () => {
    beforeAll(() => {
        setupI18n({ legacy: false, locale: "de", messages: { de } });
    });

    beforeEach(() => {
        resetInertia();
        setPage({
            props: { csrfToken: "test-csrf-token", requiresPasswordConfirmation: false, twoFactorEnabled: false }
        });
        useTwoFactorAuth().clearTwoFactorAuthData();
        useTwoFactorAuth().validationErrors.value = {};
        vi.unstubAllGlobals();
    });

    describe("setup data", () => {
        it("stores the QR code SVG", async () => {
            mockFetchSequence([{ status: 200, body: { svg: "<svg>qr</svg>", url: "otpauth://x" } }]);
            const { fetchQrCode, qrCodeSvg } = useTwoFactorAuth();

            await fetchQrCode();

            expect(qrCodeSvg.value).toBe("<svg>qr</svg>");
        });

        it("records a translated error and clears the QR code when the fetch fails", async () => {
            mockFetchSequence([{ status: 500 }]);
            const { fetchQrCode, qrCodeSvg, errors } = useTwoFactorAuth();

            await fetchQrCode();

            expect(qrCodeSvg.value).toBeNull();
            expect(errors.value).toContain(translate("dashboard.twoFactor.errors.qrCode"));
        });

        it("stores the manual setup key", async () => {
            mockFetchSequence([{ status: 200, body: { secretKey: "JBSWY3DPEHPK3PXP" } }]);
            const { fetchSetupKey, manualSetupKey } = useTwoFactorAuth();

            await fetchSetupKey();

            expect(manualSetupKey.value).toBe("JBSWY3DPEHPK3PXP");
        });

        it("reports setup data as ready only once BOTH pieces have arrived", async () => {
            mockFetchSequence([
                { status: 200, body: { svg: "<svg>qr</svg>" } },
                { status: 500 }
            ]);
            const { fetchSetupData, hasSetupData } = useTwoFactorAuth();

            await fetchSetupData();

            // The QR code alone is not a usable setup screen.
            expect(hasSetupData.value).toBe(false);
        });

        it("loads both pieces together", async () => {
            mockFetchSequence([
                { status: 200, body: { svg: "<svg>qr</svg>" } },
                { status: 200, body: { secretKey: "JBSWY3DPEHPK3PXP" } }
            ]);
            const { fetchSetupData, hasSetupData } = useTwoFactorAuth();

            await fetchSetupData();

            expect(hasSetupData.value).toBe(true);
        });

        it("clearSetupData wipes the enrollment screen", async () => {
            mockFetchSequence([
                { status: 200, body: { svg: "<svg>qr</svg>" } },
                { status: 200, body: { secretKey: "JBSWY3DPEHPK3PXP" } }
            ]);
            const twoFactor = useTwoFactorAuth();
            await twoFactor.fetchSetupData();

            twoFactor.clearSetupData();

            expect(twoFactor.hasSetupData.value).toBe(false);
            expect(twoFactor.errors.value).toStrictEqual([]);
        });
    });

    describe("recovery codes", () => {
        it("stores the fetched codes", async () => {
            mockFetchSequence([{ status: 200, body: ["code-eins", "code-zwei"] }]);
            const { fetchRecoveryCodes, recoveryCodesList } = useTwoFactorAuth();

            await fetchRecoveryCodes();

            expect(recoveryCodesList.value).toStrictEqual(["code-eins", "code-zwei"]);
        });

        it("empties the list and records an error when the fetch fails", async () => {
            mockFetchSequence([{ status: 500 }]);
            const { fetchRecoveryCodes, recoveryCodesList, errors } = useTwoFactorAuth();

            await fetchRecoveryCodes();

            expect(recoveryCodesList.value).toStrictEqual([]);
            expect(errors.value).toContain(translate("dashboard.twoFactor.errors.recoveryCodesLoad"));
        });

        it("reveals the codes once they load", async () => {
            mockFetchSequence([{ status: 200, body: ["code-eins"] }]);
            const { handleShowRecoveryCodes, isRecoveryCodesVisible, processing } = useTwoFactorAuth();

            await handleShowRecoveryCodes("passwort");

            expect(isRecoveryCodesVisible.value).toBe(true);
            expect(processing.value).toBe(false);
        });

        it("does not reveal an empty list", async () => {
            mockFetchSequence([{ status: 500 }]);
            const { handleShowRecoveryCodes, isRecoveryCodesVisible } = useTwoFactorAuth();

            await handleShowRecoveryCodes("passwort");

            expect(isRecoveryCodesVisible.value).toBe(false);
        });
    });

    describe("password confirmation guard", () => {
        it("skips the confirmation request when the session does not need it", async () => {
            setPage({ props: { requiresPasswordConfirmation: false } });
            const fetchMock = mockFetchSequence([]);
            const { enableTwoFactor } = useTwoFactorAuth();

            await enableTwoFactor("passwort");

            expect(fetchMock).not.toHaveBeenCalled();
            expect(routerCalls[0]).toMatchObject({ method: "post", url: "/user/two-factor-authentication" });
        });

        it("confirms the password first when the session requires it", async () => {
            setPage({ props: { requiresPasswordConfirmation: true } });
            const fetchMock = mockFetchSequence([{ status: 200 }]);
            const { enableTwoFactor } = useTwoFactorAuth();

            await enableTwoFactor("passwort");

            expect(fetchMock.mock.calls[0][0]).toBe("/confirm-password");
            expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toStrictEqual({ password: "passwort" });
            expect(routerCalls).toHaveLength(1);
        });

        it("aborts without touching the router when the password is rejected", async () => {
            setPage({ props: { requiresPasswordConfirmation: true } });
            mockFetchSequence([{ status: 422, body: { errors: { password: ["Das Passwort ist falsch."] } } }]);
            const { enableTwoFactor, validationErrors, processing } = useTwoFactorAuth();

            await enableTwoFactor("falsch");

            expect(validationErrors.value.password).toBe("Das Passwort ist falsch.");
            expect(routerCalls).toHaveLength(0);
            // No stuck spinner — the button has to come back.
            expect(processing.value).toBe(false);
        });
    });

    describe("enable / disable", () => {
        it("opens the setup modal once enabling succeeds", async () => {
            mockFetchSequence([]);
            const { enableTwoFactor, showSetupModal } = useTwoFactorAuth();
            await enableTwoFactor("passwort");

            // Drive the Inertia callbacks the mock recorded.
            (routerCalls[0].options?.onSuccess as () => void)();

            expect(showSetupModal.value).toBe(true);
        });

        it("preserves state and scroll when enabling, so the dashboard does not jump", async () => {
            mockFetchSequence([]);

            await useTwoFactorAuth().enableTwoFactor("passwort");

            expect(routerCalls[0].options).toMatchObject({ preserveState: true, preserveScroll: true });
        });

        it("clears processing when the visit finishes", async () => {
            mockFetchSequence([]);
            const { enableTwoFactor, processing } = useTwoFactorAuth();
            await enableTwoFactor("passwort");
            expect(processing.value).toBe(true);

            (routerCalls[0].options?.onFinish as () => void)();

            expect(processing.value).toBe(false);
        });

        it("deletes rather than posting when disabling", async () => {
            mockFetchSequence([]);

            await useTwoFactorAuth().disableTwoFactor("passwort");

            // router.delete, not a <Form> — a Form hits a 405 when Fortify's middleware
            // tries to redirect to the GET password.confirm route.
            expect(routerCalls[0]).toMatchObject({ method: "delete", url: "/user/two-factor-authentication" });
        });

        it("wipes every trace of the old enrollment once disabling succeeds", async () => {
            mockFetchSequence([{ status: 200, body: ["code-eins"] }]);
            const twoFactor = useTwoFactorAuth();
            await twoFactor.fetchRecoveryCodes();
            twoFactor.showSetupModal.value = true;

            mockFetchSequence([]);
            await twoFactor.disableTwoFactor("passwort");
            (routerCalls[0].options?.onSuccess as () => void)();

            // Stale codes from a previous enrollment must not survive.
            expect(twoFactor.recoveryCodesList.value).toStrictEqual([]);
            expect(twoFactor.isRecoveryCodesVisible.value).toBe(false);
            expect(twoFactor.showSetupModal.value).toBe(false);
            expect(twoFactor.hasSetupData.value).toBe(false);
        });
    });

    describe("regenerating recovery codes", () => {
        it("regenerates and re-reads the list when the session is still confirmed", async () => {
            const fetchMock = mockFetchSequence([
                { status: 200 },
                { status: 200, body: ["neu-eins", "neu-zwei"] }
            ]);
            const { handleRegenerateRecoveryCodes, recoveryCodesList, isRecoveryCodesVisible } = useTwoFactorAuth();

            await handleRegenerateRecoveryCodes("passwort");

            expect(fetchMock.mock.calls[0][0]).toBe("/user/two-factor-recovery-codes");
            expect(fetchMock.mock.calls[0][1].method).toBe("POST");
            expect(recoveryCodesList.value).toStrictEqual(["neu-eins", "neu-zwei"]);
            expect(isRecoveryCodesVisible.value).toBe(true);
        });

        it("re-confirms the password and retries after a 423", async () => {
            // 423 = the session's password confirmation has expired. Only then may the
            // user be asked again — this is the branch that is near-untestable by hand.
            setPage({ props: { requiresPasswordConfirmation: true } });
            const fetchMock = mockFetchSequence([
                { status: 423 },
                { status: 200 },
                { status: 200 },
                { status: 200, body: ["neu-eins"] }
            ]);
            const { handleRegenerateRecoveryCodes, recoveryCodesList } = useTwoFactorAuth();

            await handleRegenerateRecoveryCodes("passwort");

            expect(fetchMock.mock.calls[1][0]).toBe("/confirm-password");
            expect(fetchMock.mock.calls[2][0]).toBe("/user/two-factor-recovery-codes");
            expect(recoveryCodesList.value).toStrictEqual(["neu-eins"]);
        });

        it("gives up when the re-confirmation fails", async () => {
            setPage({ props: { requiresPasswordConfirmation: true } });
            mockFetchSequence([
                { status: 423 },
                { status: 422, body: { errors: { password: ["Das Passwort ist falsch."] } } }
            ]);
            const { handleRegenerateRecoveryCodes, validationErrors, processing } = useTwoFactorAuth();

            await handleRegenerateRecoveryCodes("falsch");

            expect(validationErrors.value.password).toBe("Das Passwort ist falsch.");
            expect(processing.value).toBe(false);
        });

        it("does not re-prompt on a 423 when the session never required confirmation", async () => {
            setPage({ props: { requiresPasswordConfirmation: false } });
            const fetchMock = mockFetchSequence([{ status: 423 }]);
            const { handleRegenerateRecoveryCodes, errors } = useTwoFactorAuth();

            await handleRegenerateRecoveryCodes("passwort");

            expect(fetchMock).toHaveBeenCalledOnce();
            expect(errors.value).toContain(translate("dashboard.twoFactor.errors.recoveryCodesRegenerate"));
        });

        it("records a translated error when regenerating fails outright", async () => {
            mockFetchSequence([{ status: 500 }]);
            const { handleRegenerateRecoveryCodes, errors, processing } = useTwoFactorAuth();

            await handleRegenerateRecoveryCodes("passwort");

            expect(errors.value).toContain(translate("dashboard.twoFactor.errors.recoveryCodesRegenerate"));
            expect(processing.value).toBe(false);
        });
    });

    it("shares state across consumers, so the modal and the panel agree", async () => {
        mockFetchSequence([{ status: 200, body: { svg: "<svg>qr</svg>" } }]);
        const modal = useTwoFactorAuth();
        const panel = useTwoFactorAuth();

        await modal.fetchQrCode();

        expect(panel.qrCodeSvg.value).toBe("<svg>qr</svg>");
    });

    it("keeps processing per consumer, so one form does not disable another", () => {
        const first = useTwoFactorAuth();
        const second = useTwoFactorAuth();

        first.processing.value = true;

        expect(second.processing.value).toBe(false);
    });
});
