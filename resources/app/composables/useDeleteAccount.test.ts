import { beforeEach, describe, expect, it, vi } from "vitest";
import { useDeleteAccount } from "Composables/useDeleteAccount";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * useDeleteAccount uses fetch() rather than router.delete() so that a WRONG PASSWORD
 * does not trigger a full Inertia visit on the dashboard sitting behind the modal — no
 * scroll jump, no global errors bag, the error stays local to the modal. That is the
 * property these tests pin: on a 422, nothing may reach the router.
 */

/** Queue one fetch response. */
const mockFetch = (status: number, body: unknown = {}) => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body)
    });
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

describe("useDeleteAccount", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { csrfToken: "test-csrf-token" } });
        vi.unstubAllGlobals();
    });

    it("sends the password to the delete endpoint with the CSRF token", async () => {
        const fetchMock = mockFetch(200, { redirect: "/" });

        await useDeleteAccount().deleteAccount("passwort");

        expect(fetchMock.mock.calls[0][0]).toBe("/user/delete");
        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
        expect(fetchMock.mock.calls[0][1].headers["X-CSRF-TOKEN"]).toBe("test-csrf-token");
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toStrictEqual({ password: "passwort" });
    });

    it("follows the redirect through Inertia on success", async () => {
        mockFetch(200, { redirect: "/goodbye" });

        await useDeleteAccount().deleteAccount("passwort");

        expect(routerCalls).toStrictEqual([{ method: "visit", url: "/goodbye", options: undefined }]);
    });

    it("falls back to the root when no redirect is given", async () => {
        mockFetch(200, {});

        await useDeleteAccount().deleteAccount("passwort");

        expect(routerCalls[0].url).toBe("/");
    });

    it("keeps a wrong password local to the modal, with no Inertia visit", async () => {
        mockFetch(422, { errors: { password: ["Das Passwort ist falsch."] } });
        const { deleteAccount, passwordError } = useDeleteAccount();

        await deleteAccount("falsch");

        expect(passwordError.value).toBe("Das Passwort ist falsch.");
        // The whole reason this is fetch() and not router.delete().
        expect(routerCalls).toHaveLength(0);
    });

    it("accepts a bare string error as well as an array", async () => {
        mockFetch(422, { errors: { password: "Das Passwort ist falsch." } });
        const { deleteAccount, passwordError } = useDeleteAccount();

        await deleteAccount("falsch");

        expect(passwordError.value).toBe("Das Passwort ist falsch.");
    });

    it("survives a 422 whose body is not JSON", async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
            json: () => Promise.reject(new Error("not json"))
        });
        vi.stubGlobal("fetch", fetchMock);
        const { deleteAccount, passwordError } = useDeleteAccount();

        await expect(deleteAccount("falsch")).resolves.toBeUndefined();
        expect(passwordError.value).toBe("");
    });

    it("clears a previous error when retrying", async () => {
        mockFetch(422, { errors: { password: ["falsch"] } });
        const { deleteAccount, passwordError } = useDeleteAccount();
        await deleteAccount("falsch");

        mockFetch(200, { redirect: "/" });
        await deleteAccount("richtig");

        expect(passwordError.value).toBe("");
    });

    it("always clears processing, even on a server error", async () => {
        mockFetch(500);
        const { deleteAccount, processing } = useDeleteAccount();

        await deleteAccount("passwort");

        // finally-block guarantee — a stuck spinner would leave the modal unusable.
        expect(processing.value).toBe(false);
        expect(routerCalls).toHaveLength(0);
    });

    it("clears processing when fetch itself rejects", async () => {
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
        const { deleteAccount, processing } = useDeleteAccount();

        await expect(deleteAccount("passwort")).rejects.toThrow("offline");
        expect(processing.value).toBe(false);
    });
});
