import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { debounce } from "Utils/debounce";

/*
 * debounce() is small but carries the max-wait rule that usePasswordEntropy depends
 * on: a user who never stops typing must still get feedback. That branch is easy to
 * break while "fixing" the trailing call, and impossible to notice by hand — hence
 * fake timers and explicit clock advances rather than real waits.
 */

describe("debounce", () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("collapses a burst into a single trailing call", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100);

        debounced();
        debounced();
        debounced();
        expect(spy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(100);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it("restarts the quiet period on every call", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100);

        debounced();
        vi.advanceTimersByTime(90);
        debounced();
        vi.advanceTimersByTime(90);

        // 180ms elapsed, but never 100ms of quiet.
        expect(spy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(10);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it("passes the most recent arguments, not the first", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100);

        debounced("first");
        debounced("second");
        debounced("third");
        vi.advanceTimersByTime(100);

        expect(spy).toHaveBeenCalledExactlyOnceWith("third");
    });

    it("still fires under an unbroken stream once maxWait elapses", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100, 500);

        // Call every 50ms — the quiet period never completes on its own.
        for (let elapsed = 0; elapsed < 500; elapsed += 50) {
            debounced();
            vi.advanceTimersByTime(50);
        }

        expect(spy).toHaveBeenCalledTimes(1);
    });

    it("starts the max-wait clock once per burst, not per call", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100, 300);

        debounced();
        vi.advanceTimersByTime(50);
        debounced();
        vi.advanceTimersByTime(50);
        debounced();

        // 100ms in. If each call had restarted the max timer, nothing would fire
        // until 400ms; it must fire at 300ms from the FIRST call.
        vi.advanceTimersByTime(200);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it("clears the pending trailing timer when the max-wait run fires", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100, 200);

        // Call every 50ms so the 100ms quiet period never completes: the ONLY thing
        // that can fire is the max-wait timer, at t=200.
        debounced();
        vi.advanceTimersByTime(50);
        debounced();
        vi.advanceTimersByTime(50);
        debounced();
        vi.advanceTimersByTime(50);
        debounced();
        vi.advanceTimersByTime(50);

        expect(spy).toHaveBeenCalledTimes(1);

        // The trailing timer scheduled at t=150 would land at t=250. invoke() clears
        // both timers, so it must not produce a second call.
        vi.advanceTimersByTime(1000);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it("drops a pending call on cancel", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100);

        debounced();
        debounced.cancel();
        vi.advanceTimersByTime(1000);

        expect(spy).not.toHaveBeenCalled();
    });

    it("cancels the max-wait timer too, not just the trailing one", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100, 200);

        debounced();
        vi.advanceTimersByTime(50);
        debounced.cancel();
        vi.advanceTimersByTime(1000);

        expect(spy).not.toHaveBeenCalled();
    });

    it("works again after a cancel", () => {
        const spy = vi.fn();
        const debounced = debounce(spy, 100);

        debounced();
        debounced.cancel();
        debounced("after");
        vi.advanceTimersByTime(100);

        expect(spy).toHaveBeenCalledExactlyOnceWith("after");
    });
});
