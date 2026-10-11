import { beforeEach, describe, expect, it, vi } from "vitest";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import { resetInertia } from "Testing/inertia";
import type { TestLocale } from "Testing/mount";
import { iconNames, mountApp, translate } from "Testing/mount";
import type { AudiobookStats } from "Types/audiobooks";
import AudiobookStatsWidget from "./AudiobookStatsWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The audiobook card's two durations — the chapters' playing time and everybody's listening time.
 *
 * What PHP cannot see here is the wiring from a raw number to a printed phrase, and which words
 * the card borrows: the listening tile takes the MUSIC card's label (the same fact about a
 * different shelf) but an audiobook hint of its own, because "each play counts the song's full
 * length" would be wrong about chapters. A label lookup that drifted to the audiobook keys, or a
 * hint that drifted to the music ones, would read fine and say the wrong thing. AudiobooksPageTest
 * pins the props; StatsWidget.test.ts carries the cases the two cards share through StatTiles.
 */

/** A small shelf with numbers that make each formatting choice visible. */
const stats = (overrides: Partial<AudiobookStats> = {}): AudiobookStats => ({
    books: 21,
    chapters: 1438,
    sizeBytes: 12 * 1024 ** 3,
    playtimeSeconds: 40 * 86400 + 3600,
    listenedSeconds: 2 * 86400 + 5 * 60,
    authors: 11,
    narrators: 7,
    firstYear: 2004,
    lastYear: 2023,
    ...overrides
});

/** Mount the card as the Audiobooks page builds it, in German unless a case is about English. */
const widget = (overrides: Partial<AudiobookStats> = {}, locale: TestLocale = "de") =>
    mountApp(AudiobookStatsWidget, { props: stats(overrides), locale });

/** The tile drawn under one label, or undefined when the card drew none. */
const cellFor = (wrapper: ReturnType<typeof widget>, name: string) =>
    wrapper.findAll(".widget-stats__cell").find(cell => cell.find(".widget-stats__label").text() === name);

/** The value printed on that tile. */
const valueFor = (wrapper: ReturnType<typeof widget>, name: string): string | undefined =>
    cellFor(wrapper, name)?.find(".widget-stats__value").text();

/** The listening tile's label — borrowed from the music card, see the file comment. */
const listened = (locale: TestLocale = "de"): string => translate("music.stats.label.listened", locale);

describe("AudiobookStatsWidget", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("draws the playing time and the listening time as its phrase block, in that order", () => {
        const blocks = widget().findAll(".widget-stats__lines--phrases");

        expect(blocks).toHaveLength(1);
        expect(blocks[0].findAll(".widget-stats__label").map(node => node.text()))
            .toStrictEqual([translate("audiobooks.columns.playtime"), listened()]);
    });

    it("spells everybody's listening time out as a phrase, starting at its largest unit", () => {
        expect(valueFor(widget(), listened())).toBe("2 Tage, 0 Stunden, 5 Minuten, 0 Sekunden");
    });

    it("writes it in the reader's language", () => {
        expect(valueFor(widget({}, "en"), listened("en"))).toBe("2 days, 0 hours, 5 minutes, 0 seconds");
    });

    it("explains the listening time in chapters rather than in songs", () => {
        const hint = cellFor(widget(), listened())!.findComponent(Tooltip).props("text");

        expect(hint).toBe(translate("audiobooks.stats.hint.listened"));
        expect(hint).not.toBe(translate("music.stats.hint.listened"));
    });

    it("marks it with the play pips' glyph, as the music card does", () => {
        expect(iconNames(cellFor(widget(), listened())!)).toStrictEqual(["plays"]);
    });
});
