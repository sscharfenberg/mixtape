import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useClipboard } from "Composables/useClipboard";

/*
 * useClipboard has two paths — the modern async Clipboard API and the legacy
 * execCommand fallback for restricted WebViews — plus a rule that matters to the UI:
 * clipboard failures are SWALLOWED. A denied permission (common on mobile, entirely
 * outside our control) must not throw into a click handler and break the page.
 *
 * Note what happy-dom cannot do: actually hold a clipboard. The fallback test asserts
 * the DOM dance (a textarea is created, used, and removed again) rather than pretending
 * to verify that text landed anywhere.
 */

describe("useClipboard", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it("writes through the async Clipboard API when it is available", async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal("navigator", { clipboard: { writeText } });
        const { copy } = useClipboard();

        await copy("https://mixtape/song/42");

        expect(writeText).toHaveBeenCalledExactlyOnceWith("https://mixtape/song/42");
    });

    it("flags copied, then resets itself after two seconds", async () => {
        vi.stubGlobal("navigator", { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
        const { copied, copy } = useClipboard();
        expect(copied.value).toBe(false);

        await copy("etwas");
        expect(copied.value).toBe(true);

        vi.advanceTimersByTime(1999);
        expect(copied.value).toBe(true);

        vi.advanceTimersByTime(1);
        expect(copied.value).toBe(false);
    });

    it("falls back to a temporary textarea when the Clipboard API is missing", async () => {
        vi.stubGlobal("navigator", {});
        const execCommand = vi.spyOn(document, "execCommand").mockReturnValue(true);
        const appendChild = vi.spyOn(document.body, "appendChild");
        const removeChild = vi.spyOn(document.body, "removeChild");
        const { copy, copied } = useClipboard();

        await copy("Fallback-Text");

        expect(execCommand).toHaveBeenCalledWith("copy");
        const appended = appendChild.mock.calls[0][0] as HTMLTextAreaElement;
        expect(appended.tagName).toBe("TEXTAREA");
        expect(appended.value).toBe("Fallback-Text");
        expect(copied.value).toBe(true);

        // The scratch element must not survive — it would pile up one per copy.
        expect(removeChild).toHaveBeenCalledWith(appended);
        expect(document.querySelector("textarea")).toBeNull();
    });

    it("positions the fallback textarea off-screen so nothing flashes", async () => {
        vi.stubGlobal("navigator", {});
        vi.spyOn(document, "execCommand").mockReturnValue(true);
        const appendChild = vi.spyOn(document.body, "appendChild");

        await useClipboard().copy("Fallback-Text");

        const appended = appendChild.mock.calls[0][0] as HTMLTextAreaElement;
        expect(appended.style.position).toBe("fixed");
        expect(appended.style.opacity).toBe("0");
    });

    it("swallows a denied clipboard permission instead of throwing into the caller", async () => {
        vi.stubGlobal("navigator", {
            clipboard: { writeText: vi.fn().mockRejectedValue(new Error("NotAllowedError")) }
        });
        const { copy, copied } = useClipboard();

        await expect(copy("etwas")).resolves.toBeUndefined();
        expect(copied.value).toBe(false);
    });

    it("gives each consumer its own copied flag", async () => {
        vi.stubGlobal("navigator", { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
        const first = useClipboard();
        const second = useClipboard();

        await first.copy("etwas");

        // Two copy buttons on one page must not light each other up.
        expect(first.copied.value).toBe(true);
        expect(second.copied.value).toBe(false);
    });
});
