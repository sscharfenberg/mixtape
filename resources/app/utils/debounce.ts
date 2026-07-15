/**
 * A debounced function: the original plus a `cancel()` to drop a pending call.
 */
export type Debounced<A extends unknown[]> = ((...args: A) => void) & {
    cancel: () => void;
};

/**
 * Debounce `fn`: postpone invoking it until `delay` ms have elapsed since the
 * last call, so a burst of rapid calls collapses into a single trailing run
 * (always with the most recent arguments).
 *
 * With `maxWait`, also guarantee `fn` runs at least once every `maxWait` ms even
 * under an unbroken stream of calls — otherwise a user who never pauses (e.g.
 * types continuously) would never trigger the trailing run. This is the pattern
 * the password-strength check needs (see usePasswordEntropy): react shortly
 * after the user stops typing, but don't stay silent forever mid-typing.
 *
 * The returned function carries a `cancel()` that clears any pending invocation.
 *
 * @param fn      the function to debounce
 * @param delay   quiet period in ms before the trailing run fires
 * @param maxWait optional cap in ms on how long calls can be deferred
 */
export function debounce<A extends unknown[]>(
    fn: (...args: A) => void,
    delay: number,
    maxWait?: number
): Debounced<A> {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let maxTimer: ReturnType<typeof setTimeout> | null = null;
    let lastArgs: A | null = null;

    const clear = (): void => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        if (maxTimer !== null) {
            clearTimeout(maxTimer);
            maxTimer = null;
        }
    };

    const invoke = (): void => {
        const args = lastArgs;
        clear();
        if (args !== null) fn(...args);
    };

    const debounced = (...args: A): void => {
        lastArgs = args;
        if (timer !== null) clearTimeout(timer);
        timer = setTimeout(invoke, delay);
        // Start the max-wait clock once per burst; it survives resets of `timer`.
        if (maxWait !== undefined && maxTimer === null) {
            maxTimer = setTimeout(invoke, maxWait);
        }
    };

    debounced.cancel = clear;

    return debounced;
}
