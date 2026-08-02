import { beforeEach, describe, expect, it, vi } from "vitest";
import { useLogin } from "Composables/useLogin";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * useLogin drives the whole login + 2FA challenge flow on ONE page. It talks JSON to
 * Fortify on purpose: that is what makes Fortify answer `{ two_factor: true }` instead
 * of redirecting to a separate challenge view, and the entire single-page challenge
 * depends on it. So these tests assert the request as much as the response handling —
 * the Accept/X-Requested-With headers are load-bearing, not incidental.
 *
 * The branch worth guarding hardest: a 2FA response must NOT navigate, and must clear
 * the password. Getting that wrong either strands the user or leaves their password
 * sitting in a live ref through the whole challenge.
 */

/** Queue one fetch response. `body` is returned from .json(). */
const mockFetch = (status: number, body: unknown = {}) => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body)
    });
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

/** The parsed body of the nth fetch call. */
const bodyOf = (fetchMock: ReturnType<typeof vi.fn>, index = 0): Record<string, unknown> =>
    JSON.parse(fetchMock.mock.calls[index][1].body);

/** The headers of the nth fetch call. */
const headersOf = (fetchMock: ReturnType<typeof vi.fn>, index = 0): Record<string, string> =>
    fetchMock.mock.calls[index][1].headers;

describe("useLogin", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { csrfToken: "test-csrf-token" } });
        vi.unstubAllGlobals();
    });

    it("posts credentials as JSON so Fortify answers with a 2FA flag instead of redirecting", async () => {
        const fetchMock = mockFetch(200, { two_factor: false, redirect: "/dashboard" });
        const login = useLogin();
        login.name.value = "Ashaltiriak";
        login.password.value = "passwort";

        await login.submit();

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(fetchMock.mock.calls[0][0]).toBe("/login");
        expect(headersOf(fetchMock)).toMatchObject({
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": "test-csrf-token"
        });
        expect(bodyOf(fetchMock)).toStrictEqual({
            name: "Ashaltiriak",
            password: "passwort",
            remember: true
        });
    });

    it("defaults remember to true", () => {
        expect(useLogin().remember.value).toBe(true);
    });

    it("follows the redirect on a successful login", async () => {
        mockFetch(200, { two_factor: false, redirect: "/music" });
        const login = useLogin();

        await login.submit();

        expect(routerCalls).toStrictEqual([{ method: "visit", url: "/music", options: undefined }]);
    });

    it("falls back to the dashboard when the response names no redirect", async () => {
        mockFetch(200, {});
        const login = useLogin();

        await login.submit();

        expect(routerCalls[0].url).toBe("/dashboard");
    });

    it("flattens Fortify's per-field error arrays to the first message", async () => {
        mockFetch(422, {
            errors: {
                name: ["Diese Zugangsdaten passen nicht.", "Zweite Meldung"],
                password: ["Pflichtfeld"]
            }
        });
        const login = useLogin();

        await login.submit();

        expect(login.errors.value).toStrictEqual({
            name: "Diese Zugangsdaten passen nicht.",
            password: "Pflichtfeld"
        });
    });

    it("does not navigate on a validation failure", async () => {
        mockFetch(422, { errors: { name: ["falsch"] } });
        const login = useLogin();

        await login.submit();

        expect(routerCalls).toHaveLength(0);
        expect(login.processing.value).toBe(false);
    });

    it("clears stale errors when a new attempt starts", async () => {
        mockFetch(422, { errors: { name: ["falsch"] } });
        const login = useLogin();
        await login.submit();
        expect(login.errors.value).not.toStrictEqual({});

        mockFetch(200, { redirect: "/dashboard" });
        await login.submit();

        expect(login.errors.value).toStrictEqual({});
    });

    it("resets processing after a server error so the user can retry", async () => {
        mockFetch(500);
        const login = useLogin();

        await login.submit();

        expect(login.processing.value).toBe(false);
        expect(routerCalls).toHaveLength(0);
    });

    describe("two-factor challenge", () => {
        it("stays on the page and clears the password when 2FA is required", async () => {
            mockFetch(200, { two_factor: true });
            const login = useLogin();
            login.password.value = "passwort";

            await login.submit();

            expect(login.requiresTwoFactor.value).toBe(true);
            // Nothing navigates — the challenge renders in place on the login page.
            expect(routerCalls).toHaveLength(0);
            // And the password must not linger in a live ref for the whole challenge.
            expect(login.password.value).toBe("");
            expect(login.processing.value).toBe(false);
        });

        it("sends the next submit to the challenge endpoint as an authenticator code", async () => {
            mockFetch(200, { two_factor: true });
            const login = useLogin();
            await login.submit();

            const fetchMock = mockFetch(200, { redirect: "/dashboard" });
            login.recoveryCode.value = "123456";
            await login.submit();

            expect(fetchMock.mock.calls[0][0]).toBe("/two-factor-challenge");
            expect(bodyOf(fetchMock)).toStrictEqual({ code: "123456" });
        });

        it("sends a recovery code under its own key when that mode is on", async () => {
            mockFetch(200, { two_factor: true });
            const login = useLogin();
            await login.submit();

            const fetchMock = mockFetch(200, { redirect: "/dashboard" });
            login.showRecoveryCode.value = true;
            login.recoveryCode.value = "abcd-efgh";
            await login.submit();

            // Same input ref, different payload key — Fortify distinguishes the two.
            expect(bodyOf(fetchMock)).toStrictEqual({ recovery_code: "abcd-efgh" });
        });

        it("surfaces a rejected code without leaving the challenge", async () => {
            mockFetch(200, { two_factor: true });
            const login = useLogin();
            await login.submit();

            mockFetch(422, { errors: { code: ["Der Code ist ungültig."] } });
            await login.submit();

            expect(login.errors.value.code).toBe("Der Code ist ungültig.");
            expect(login.requiresTwoFactor.value).toBe(true);
            expect(routerCalls).toHaveLength(0);
        });

        it("navigates once the challenge passes", async () => {
            mockFetch(200, { two_factor: true });
            const login = useLogin();
            await login.submit();

            mockFetch(200, { redirect: "/dashboard" });
            login.recoveryCode.value = "123456";
            await login.submit();

            expect(routerCalls).toStrictEqual([{ method: "visit", url: "/dashboard", options: undefined }]);
        });
    });
});
