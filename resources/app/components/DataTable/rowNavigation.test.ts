import { afterEach, describe, expect, it, vi } from "vitest";
import { isRowNavigation } from "./rowNavigation";

/*
 * isRowNavigation decides whether a click on a table row means "open this row". Every
 * one of its guards exists because of a bug a user notices immediately — a checkbox that
 * also navigates, a drag-selected song title that throws the listing away, a ⌘-click
 * that should open a new tab but instead hijacks the page. Each guard gets a test.
 */

/** Build a click event whose target is `target`, dispatched so `event.target` is real. */
const clickOn = (target: Element, init: MouseEventInit = {}): MouseEvent => {
    const event = new window.MouseEvent("click", { bubbles: true, ...init });
    target.dispatchEvent(event);

    return event;
};

/** A row containing `innerHTML`, attached to the document so closest() works. */
const rowWith = (innerHTML: string): HTMLElement => {
    const row = document.createElement("tr");
    row.innerHTML = innerHTML;
    document.body.appendChild(row);

    return row;
};

/** Pretend the user has (or has not) got text selected in the page. */
const stubSelection = (isCollapsed: boolean): void => {
    vi.spyOn(window, "getSelection").mockReturnValue({ isCollapsed } as Selection);
};

describe("isRowNavigation", () => {
    afterEach(() => {
        document.body.innerHTML = "";
        vi.restoreAllMocks();
    });

    it("navigates on a plain click on the row itself", () => {
        stubSelection(true);
        const row = rowWith("<td>Paranoid Android</td>");

        expect(isRowNavigation(clickOn(row))).toBe(true);
    });

    it("navigates on a click on inert content inside a cell", () => {
        stubSelection(true);
        const row = rowWith("<td><span id='text'>Paranoid Android</span></td>");

        expect(isRowNavigation(clickOn(row.querySelector("#text")!))).toBe(true);
    });

    it.each([
        ["ctrlKey", { ctrlKey: true }],
        ["metaKey", { metaKey: true }],
        ["shiftKey", { shiftKey: true }],
        ["altKey", { altKey: true }]
    ])("leaves an %s click to the real link, since router.visit cannot open elsewhere", (_name, init) => {
        stubSelection(true);
        const row = rowWith("<td>Paranoid Android</td>");

        expect(isRowNavigation(clickOn(row, init))).toBe(false);
    });

    it.each([
        ["a", "<a href='/music/songs/1'>Titel</a>"],
        ["button", "<button>Aktionen</button>"],
        ["input", "<input type='checkbox' />"],
        ["select", "<select><option>eins</option></select>"],
        ["textarea", "<textarea></textarea>"],
        ["label", "<label>Auswahl</label>"],
        ["summary", "<summary>Mehr</summary>"],
        ['[role="button"]', "<span role='button'>Fake</span>"],
        ['[contenteditable="true"]', "<span contenteditable='true'>Text</span>"]
    ])("does not navigate when the click lands on %s, which owns its own click", (_name, markup) => {
        stubSelection(true);
        const row = rowWith(`<td>${markup}</td>`);
        const control = row.querySelector("td")!.firstElementChild!;

        expect(isRowNavigation(clickOn(control))).toBe(false);
    });

    it("does not navigate when the click lands inside a control, not just on it", () => {
        // The actions button contains an <svg>; the click target is the icon, not the button.
        stubSelection(true);
        const row = rowWith("<td><button><span id='icon'>x</span></button></td>");

        expect(isRowNavigation(clickOn(row.querySelector("#icon")!))).toBe(false);
    });

    it("does not navigate when the user has just drag-selected text", () => {
        // A drag-select ends in a click on the row; navigating would throw the listing
        // away exactly as the text was highlighted.
        stubSelection(false);
        const row = rowWith("<td>Paranoid Android</td>");

        expect(isRowNavigation(clickOn(row))).toBe(false);
    });

    it("navigates when there is no selection object at all", () => {
        vi.spyOn(window, "getSelection").mockReturnValue(null);
        const row = rowWith("<td>Paranoid Android</td>");

        expect(isRowNavigation(clickOn(row))).toBe(true);
    });
});
