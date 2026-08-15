/******************************************************************************
 * interactive
 * "Is this element a control?" — the one list, for the two places that have to ask.
 *
 * Both are the same judgement wearing different clothes. DataTable's row navigation asks
 * it of a CLICK ("a click that landed on a link is not row navigation"); the player's
 * keyboard shortcuts ask it of a KEYPRESS ("a key pressed on a control belongs to the
 * control"). Getting either wrong is the same bug: a gesture is claimed by the page when
 * it was meant for the thing under the cursor or the caret.
 *
 * It lives here rather than in either caller because the two lists MUST NOT DRIFT. A copy in
 * each would be correct on the day it was written and wrong the first time a control was
 * added to only one of them.
 *
 * The role selectors matter as much as the tag names: this app's Select is a <button>
 * plus a `role="listbox"` of `role="option"` buttons rather than a native <select>, and a
 * native tag check sees only "a button" — which is true, and not the useful answer.
 *****************************************************************************/

/**
 * Elements that own the gesture landing on them.
 *
 * `label` is here for a checkbox's own label (it proxies the click to its input);
 * `summary` because a <details> toggle would otherwise be treated as page background; and
 * the roles for the controls this app builds out of plain elements — the Select's listbox,
 * TabbedNavigation's tabs, and anything faking a button.
 */
export const INTERACTIVE_SELECTOR =
    'a, button, input, select, textarea, label, summary, [contenteditable="true"], ' +
    '[role="button"], [role="checkbox"], [role="listbox"], [role="menuitem"], [role="option"], ' +
    '[role="radio"], [role="slider"], [role="switch"], [role="tab"], [role="textbox"]';

/**
 * True when `target`, or anything it sits inside, is a control.
 *
 * `closest()` rather than a test on the node itself, because the thing an event reports is
 * usually a child of the control — the <svg> inside a button, the text node's parent
 * inside a label.
 */
export const isInteractive = (target: EventTarget | null): boolean =>
    target instanceof Element && target.closest(INTERACTIVE_SELECTOR) !== null;

/**
 * True when `target` is somewhere text is being entered.
 *
 * A narrower question than {@link isInteractive}, for the keys that only clash with
 * TYPING — a letter shortcut has no quarrel with a focused button, but every quarrel with
 * a password field. `input` is deliberately unqualified: a checkbox and a range are inputs
 * too, and a letter typed at either does nothing worth protecting, so treating the whole
 * family as "hands off" costs nothing and cannot be got wrong by a new input type.
 */
export const isTextEntry = (target: EventTarget | null): boolean =>
    target instanceof Element && target.closest('input, textarea, [contenteditable="true"], [role="textbox"]') !== null;
