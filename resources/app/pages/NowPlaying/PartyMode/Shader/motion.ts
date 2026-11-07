/******************************************************************************
 * motion
 * The two bits of arithmetic every visualiser's per-frame state is made of: easing a value towards
 * a target over time, and wrapping an ever-growing phase back into a period.
 *
 * BOTH ARE ABOUT THE CLOCK, NOT THE FRAME. A plugin advances its state by the seconds since the
 * last frame, never by a count of frames, so a 120 Hz monitor and a phone throttled to 30 frames a
 * second see the same picture move at the same speed.
 *****************************************************************************/

/** Wrap `value` into [0, period) — a modulo that is also right for negative numbers. */
export function wrap(value: number, period: number): number {
    return ((value % period) + period) % period;
}

/**
 * Move `current` towards `target` over `step` seconds, with time constant `lag` seconds — an
 * exponential approach, so the result never overshoots and does not depend on the frame rate.
 */
export function ease(current: number, target: number, step: number, lag: number): number {
    return current + (target - current) * (1 - Math.exp(-step / lag));
}
