/******************************************************************************
 * Scrolling helpers. Pure functions over the DOM, no state and no Vue
 * reactivity — the caller passes the element.
 *****************************************************************************/

/**
 * Bring the top of an element into view, the way a paged list should after a page turn.
 *
 * Both of the app's pagers need this and for the same reason: the pager sits UNDER the
 * rows, so turning a page from the bottom of one leaves the reader at the bottom of the
 * next, having silently skipped everything on it. The scroll position never moved; only
 * the content beneath it did, which is what reads as a jump.
 *
 * Scrolling the LIST rather than the document is the point. Inertia's own
 * `preserveScroll: false` resets to the top of the page, which on a tabbed detail page
 * means scrolling up past the hero and the tab strip — hiding the very thing that just
 * changed. Give the element a `scroll-margin-top` clearing the sticky header
 * (`--app-header-height`) and its first row lands just under it instead.
 *
 * Smooth only under `prefers-reduced-motion: no-preference`: a scroll animation is motion
 * like any other, and the app's rule is that motion is opt-in (CLAUDE.md → Motion). The
 * check is read per call rather than cached, so flipping the OS setting takes effect
 * without a reload.
 *
 * @param element the element whose top should come into view; a no-op when absent, so
 *                callers can pass an unmounted template ref without guarding
 */
export const scrollIntoViewTop = (element: HTMLElement | null | undefined): void => {
    if (!element) return;

    const smooth = window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
    element.scrollIntoView({ block: "start", behavior: smooth ? "smooth" : "auto" });
};
