import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { usePasswordEntropy } from "Composables/usePasswordEntropy";
import { resetInertia, setPage } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * usePasswordEntropy scores the password SERVER-side on purpose, so the meter agrees
 * exactly with the PasswordEntropy validation rule that will accept or reject the form.
 * The debounce around it is the interesting part in practice: it must not fire per
 * keystroke (that would be one request per character), and it must not stay silent for
 * a user who never pauses.
 */

/** Queue one entropy response. */
const mockFetch = (body: unknown = { score: 3 }, ok = true) => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok,
        status: ok ? 200 : 500,
        json: () => Promise.resolve(body)
    });
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

describe("usePasswordEntropy", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetInertia();
        setPage({ props: { csrfToken: "test-csrf-token" } });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it("starts with no score, so the meter renders nothing before the first answer", () => {
        expect(usePasswordEntropy().score.value).toBeNull();
    });

    it("posts the password to the entropy endpoint after the typing pause", async () => {
        const fetchMock = mockFetch({ score: 4 });
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "korrekt-pferd-batterie";

        onPasswordChange();
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(750);

        expect(fetchMock.mock.calls[0][0]).toBe("/password/entropy");
        expect(fetchMock.mock.calls[0][1].headers["X-CSRF-TOKEN"]).toBe("test-csrf-token");
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toStrictEqual({ p: "korrekt-pferd-batterie" });
    });

    it("stores the returned score", async () => {
        mockFetch({ score: 2 });
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "schwach";

        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        expect(score.value).toBe(2);
    });

    it("collapses a burst of keystrokes into one request", async () => {
        const fetchMock = mockFetch();
        const { password, onPasswordChange } = usePasswordEntropy();

        "geheim".split("").forEach(character => {
            password.value += character;
            onPasswordChange();
            vi.advanceTimersByTime(100);
        });
        await vi.advanceTimersByTimeAsync(750);

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it("still answers a user who never stops typing, via the max-wait", async () => {
        const fetchMock = mockFetch();
        const { password, onPasswordChange } = usePasswordEntropy();

        // 5000ms of unbroken typing, never a 750ms gap.
        for (let elapsed = 0; elapsed < 5000; elapsed += 100) {
            password.value += "x";
            onPasswordChange();
            await vi.advanceTimersByTimeAsync(100);
        }

        expect(fetchMock).toHaveBeenCalled();
    });

    it("clears the score without a request when the field is emptied", async () => {
        const fetchMock = mockFetch({ score: 4 });
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "etwas";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);
        expect(score.value).toBe(4);

        password.value = "";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        expect(score.value).toBeNull();
        // Only the first, non-empty password was ever sent.
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it("keeps the last score when the endpoint fails", async () => {
        mockFetch({ score: 3 });
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "gut";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        mockFetch({}, false);
        password.value = "gut genug";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        // A failed check must not read as "your password got weaker".
        expect(score.value).toBe(3);
    });

    it("ignores a score that answers a password already typed past", async () => {
        /*
         * TWO RUNS ARE GENUINELY IN FLIGHT AT ONCE here — the max-wait fires mid-typing and
         * the trailing run follows it — so without an identity check the slower answer paints
         * last. A reader watching the meter would see it fall back to the strength of what
         * they had a moment ago, on a password that is now longer.
         */
        let releaseStale: ((value: unknown) => void) | undefined;
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(
                () => new Promise(resolve => { releaseStale = resolve; })
            )
            .mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ score: 4 }) });
        vi.stubGlobal("fetch", fetchMock);

        const { password, score, onPasswordChange } = usePasswordEntropy();

        password.value = "geheim";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        // The reader keeps typing, and the second answer lands first.
        password.value = "geheim123";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);
        expect(score.value).toBe(4);

        // Now the older one comes back. It is about a password that is no longer on screen.
        releaseStale!({ ok: true, status: 200, json: () => Promise.resolve({ score: 0 }) });
        await vi.advanceTimersByTimeAsync(0);

        expect(score.value).toBe(4);
    });

    it("aborts the request it supersedes rather than leaving it on the wire", async () => {
        const signals: AbortSignal[] = [];
        const fetchMock = vi.fn().mockImplementation((_url: string, init: RequestInit) => {
            signals.push(init.signal!);

            return new Promise(() => {
                // Never settles: what is under test is the signal, not the answer.
            });
        });
        vi.stubGlobal("fetch", fetchMock);

        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "geheim";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        password.value = "geheim123";
        onPasswordChange();
        await vi.advanceTimersByTimeAsync(750);

        expect(signals).toHaveLength(2);
        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);
    });

    it("reset() clears the field, the score, and any pending request", async () => {
        const fetchMock = mockFetch();
        const { password, score, onPasswordChange, reset } = usePasswordEntropy();
        password.value = "etwas";
        onPasswordChange();

        reset();
        await vi.advanceTimersByTimeAsync(5000);

        expect(password.value).toBe("");
        expect(score.value).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
