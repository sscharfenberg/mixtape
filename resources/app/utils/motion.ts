/******************************************************************************
 * motion
 * "Does the reader want movement?" — the one place JavaScript asks.
 *
 * CSS asks this all over the app, and always the same way: every `transition` and every
 * decorative `animation` lives inside `@media (prefers-reduced-motion: no-preference)`
 * (CLAUDE.md → Motion). Two things cannot be expressed in CSS, though, because they are
 * options handed to a library rather than properties on an element — SortableJS's
 * `animation` in the queue's reorder and the listing's — so they have to ask in JS.
 *
 * It lives here rather than beside either caller because the two MUST NOT DRIFT: it was a
 * private helper in useQueueReorder until the playlists listing needed the same answer
 * (2026-08-08), and a second copy would have been correct the day it was written and wrong
 * the first time the query changed.
 *
 * WRITTEN POSITIVELY — `no-preference` rather than a `reduce` opt-out — matching the CSS
 * rule exactly, so a browser that reports nothing at all gets no animation either.
 *****************************************************************************/

/** Whether the reader has asked for motion. False when the preference is reduce, or unknown. */
export function prefersMotion(): boolean {
    return window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
}
