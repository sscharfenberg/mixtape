/******************************************************************************
 * rowNavigation
 * Shared by DataTableBody (<tr>) and DataTableCards (<article>): decides whether
 * a click on a row is really "open this row's detail page". Colocated with the
 * two components rather than living in a composable, because it is one pure
 * predicate over a MouseEvent and holds no state.
 *
 * A whole row is a big click target that contains other things, so a bare
 * `@click="visit(row.href)"` misfires in three ways — each of them a bug a user
 * notices immediately:
 *
 *   1. controls inside a cell (the selection checkbox, the actions button, a
 *      real <a> on the title — see README → Accessibility) own their own click;
 *      navigating as well would fight them, or double-fire the same visit
 *   2. drag-selecting a song title to copy it ends in a click on the row, which
 *      would throw the listing away just as the text was highlighted
 *   3. ⌘/ctrl/shift-click means "open elsewhere", which `router.visit()` cannot
 *      do — better to leave it to the real link in the title cell than to
 *      hijack it into a same-tab visit that loses the list
 *****************************************************************************/

/**
 * Elements that own their click. `label` is in there for the checkbox's own
 * label, `[role="button"]` for anything faking a button, and `summary` because a
 * <details> toggle inside a cell would otherwise navigate away instead of open.
 */
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [contenteditable="true"]';

/**
 * True when `event` should navigate to the row's detail page. Callers still check
 * that the row actually carries an `href` — this only judges the click itself.
 */
export const isRowNavigation = (event: MouseEvent): boolean => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return false;

    const target = event.target;
    if (target instanceof Element && target.closest(INTERACTIVE)) return false;

    // A drag-select inside the row leaves a non-collapsed selection at click
    // time; a plain click doesn't, because mousedown collapses whatever was
    // selected before the click event is dispatched.
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed) return false;

    return true;
};
