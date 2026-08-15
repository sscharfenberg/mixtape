import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { resetToastsForTests, useToast } from "Composables/useToast";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { UseShareLinkReturn } from "./useShareLink";
import { useShareLink } from "./useShareLink";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Minting a share link — the one request in the app that hands back a capability.
 *
 * WHY THIS IS A COMPOSABLE TEST AND NOT A COMPONENT ONE: everything worth pinning here is
 * about the REQUEST and what is done with its answer, and none of it is visible in markup.
 * The button and the modal have their own tests for what they draw.
 *
 * What a reader would notice going wrong:
 *
 *   - A FAILED MINT MUST LEAVE NOTHING BEHIND. `link` staying null is what keeps the modal
 *     shut; a modal opened on a failure would show an empty field the reader would copy.
 *   - THE 429 IS ITS OWN MESSAGE. The route's ceiling is low on purpose, and "you have
 *     shared a lot just now" is a different thing to be told than "that did not work".
 *   - IT MUST NOT MINT TWICE. The button is disabled while in flight, but a keyboard repeat
 *     can outrun a render, and a second row is one the reader never asked for.
 *
 * The CSRF token is asserted because it is the one header Inertia would normally add for us:
 * this is a plain `fetch`, so forgetting it is a 419 that only shows up against a real server.
 */

/** Mount a throwaway component just to run the composable inside a Vue setup scope. */
const shareLink = (): UseShareLinkReturn => {
    let api!: UseShareLinkReturn;

    setPage({ props: { auth: { user: null }, csrfToken: "a-token" } });
    mountApp(
        defineComponent({
            setup() {
                api = useShareLink();

                return () => null;
            }
        })
    );

    return api;
};

/** Stand in for the endpoint with one canned answer. */
const respond = (status: number, body: unknown = {}): void => {
    vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue({
            ok: status >= 200 && status < 300,
            status,
            json: () => Promise.resolve(body)
        })
    );
};


describe("useShareLink", () => {
    beforeEach(() => {
        resetInertia();
        resetToastsForTests();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("posts the subject and its id, with the CSRF token", async () => {
        respond(200, { url: "https://mixtape/s/abc", validUntil: "2026-08-18T12:00:00+00:00" });
        const { mint } = shareLink();

        await mint("album", "1111");

        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = vi.mocked(fetch).mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/shares");
        expect(init.method).toBe("POST");
        expect(JSON.parse(init.body as string)).toStrictEqual({ subject: "album", id: "1111" });
        expect((init.headers as Record<string, string>)["X-CSRF-TOKEN"]).toBe("a-token");
    });

    it("keeps the link the server minted, expiry and all", async () => {
        respond(200, { url: "https://mixtape/s/abc", validUntil: "2026-08-18T12:00:00+00:00" });
        const { link, mint } = shareLink();

        await mint("song", "1111");

        expect(link.value).toStrictEqual({ url: "https://mixtape/s/abc", validUntil: "2026-08-18T12:00:00+00:00" });
    });

    it("holds nothing when the mint fails, so no modal opens on an empty field", async () => {
        respond(500);
        const { link, mint } = shareLink();

        await mint("song", "1111");

        expect(link.value).toBeNull();
        expect(useToast().activeToasts.value[0].type).toBe("error");
    });

    it("says something different about a 429 than about a failure", async () => {
        respond(429);
        const { mint } = shareLink();

        await mint("song", "1111");

        const message = useToast().activeToasts.value[0].message;
        expect(message).toContain("Zu viele");
        // …and NOT the generic failure, which is the point of the branch existing.
        expect(message).not.toContain("konnte nicht erstellt werden");
    });

    it("survives the network being gone", async () => {
        // Offline, or a session that rotated under us. `fetch` REJECTS rather than resolving
        // with a status, which is a separate branch from the one above.
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
        const { link, mint } = shareLink();

        await mint("song", "1111");

        expect(link.value).toBeNull();
        expect(useToast().activeToasts.value).toHaveLength(1);
    });

    it("refuses a second press while the first is still in flight", async () => {
        // Never settles, so the guard is the only thing that can stop the second call.
        vi.stubGlobal("fetch", vi.fn().mockReturnValue(new Promise(() => {})));
        const { mint } = shareLink();

        void mint("song", "1111");
        await mint("song", "1111");

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it("forgets the link on dismiss, so reopening has to ask again", async () => {
        respond(200, { url: "https://mixtape/s/abc", validUntil: "2026-08-18T12:00:00+00:00" });
        const { link, mint, dismiss } = shareLink();

        await mint("song", "1111");
        dismiss();

        expect(link.value).toBeNull();
    });
});
