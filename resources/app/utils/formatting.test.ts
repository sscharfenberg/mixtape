import { describe, expect, it } from "vitest";
import type { DurationUnit } from "Utils/formatting";
import {
    formatClock,
    formatDateTime,
    formatDecimals,
    formatDuration,
    formatDurationParts,
    formatFileSize,
    formatPosition,
    formatTimesPlayed,
    formatYearRange
} from "Utils/formatting";

/*
 * Unit tests for the client-side formatters.
 *
 * These are the app's whole formatting layer: the server sends raw seconds / bytes /
 * ISO-8601 and every rendering decision is made here, so a regression in this file is a
 * regression in every table, card and detail page at once. Each test names the documented
 * behaviour it pins rather than the function's mechanics — the docblocks in formatting.ts
 * promise specific things (fixed decimals so columns align, a dropped denominator on
 * over-numbered tracks, null-in-null-out) and those promises are what callers rely on.
 *
 * Locale note: assertions use `de` wherever an exact string is checked. ICU rewrites
 * en-US time formatting between versions — the space before "PM" has flipped between
 * U+0020 and U+202F (narrow no-break) across releases — so exact en-US clock strings are
 * a false failure waiting for a Node upgrade. `de` has no AM/PM and is the app's default
 * locale anyway. English is asserted structurally instead.
 */

/**
 * A stand-in for the i18n `t()` that formatDuration() delegates its labels to. Returns a
 * marker like "2h" so a test can assert both the unit ORDER and the counts without pulling
 * vue-i18n and the real catalogs into a unit test — the helper's contract is only that it
 * calls this once per shown unit, largest first.
 */
const unit = (key: DurationUnit, count: number): string => `${count}${key.charAt(0)}`;

describe("environment", () => {
    /*
     * Guard, not a real test: several assertions below depend on TZ=UTC from
     * vitest.config.ts. If that ever stops being applied, formatDateTime's expectations
     * fail with a confusing two-hour drift — this fails first and says why.
     */
    it("runs with the timezone pinned to UTC", () => {
        expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe("UTC");
    });
});

describe("formatDecimals", () => {
    it("applies the locale's grouping and decimal separators", () => {
        expect(formatDecimals(1234.5, "de", 1)).toBe("1.234,5");
        expect(formatDecimals(1234.5, "en", 1)).toBe("1,234.5");
    });

    it("defaults to whole numbers, rounding away the fraction", () => {
        expect(formatDecimals(1234.5, "de")).toBe("1.235");
        expect(formatDecimals(0.4, "de")).toBe("0");
    });

    it("pads to minimumFractionDigits, which a maximum alone never does", () => {
        expect(formatDecimals(1.4, "de", 2)).toBe("1,4");
        expect(formatDecimals(1.4, "de", 2, 2)).toBe("1,40");
        expect(formatDecimals(12, "de", 2, 2)).toBe("12,00");
    });

    it("groups large numbers and handles negatives", () => {
        expect(formatDecimals(12058, "de")).toBe("12.058");
        expect(formatDecimals(-1234, "de")).toBe("-1.234");
    });
});

describe("formatFileSize", () => {
    it("uses MB below 1 GiB and GB at or above it", () => {
        expect(formatFileSize(5 * 1024 ** 2, "de")).toBe("5,00 MB");
        expect(formatFileSize(1024 ** 3 - 1, "de")).toContain("MB");
        expect(formatFileSize(1024 ** 3, "de")).toBe("1,00 GB");
    });

    it("is binary (1024-based), not decimal", () => {
        // 1_000_000 bytes is under a MiB — a decimal formatter would say "1,00 MB".
        expect(formatFileSize(1_000_000, "de")).toBe("0,95 MB");
    });

    it("always shows exactly two decimals so stacked rows align", () => {
        // The point of the fixed places: these two must be the same width.
        expect(formatFileSize(1.4 * 1024 ** 2, "de")).toBe("1,40 MB");
        expect(formatFileSize(12 * 1024 ** 2, "de")).toBe("12,00 MB");
    });

    it("formats the number in the given locale", () => {
        expect(formatFileSize(1536 * 1024 ** 2, "de")).toBe("1,50 GB");
        expect(formatFileSize(1536 * 1024 ** 2, "en")).toBe("1.50 GB");
    });

    it("renders a zero-byte file rather than blanking it", () => {
        expect(formatFileSize(0, "de")).toBe("0,00 MB");
    });
});

describe("formatClock", () => {
    it("writes m:ss under an hour, zero-padding only the seconds", () => {
        expect(formatClock(0)).toBe("0:00");
        expect(formatClock(9)).toBe("0:09");
        expect(formatClock(62)).toBe("1:02");
        expect(formatClock(599)).toBe("9:59");
    });

    it("switches to h:mm:ss past an hour, padding the minutes too", () => {
        expect(formatClock(3600)).toBe("1:00:00");
        expect(formatClock(3661)).toBe("1:01:01");
        expect(formatClock(36000)).toBe("10:00:00");
    });

    it("rounds fractional seconds", () => {
        expect(formatClock(59.4)).toBe("0:59");
        expect(formatClock(59.6)).toBe("1:00");
    });

    it("returns null for a missing or non-finite duration, so the cell can drop", () => {
        // Null in, null out — a tagless file must not read as "0:00".
        expect(formatClock(null)).toBeNull();
        expect(formatClock(Number.NaN)).toBeNull();
        expect(formatClock(Number.POSITIVE_INFINITY)).toBeNull();
    });
});

describe("formatDateTime", () => {
    it("renders date and time to the second in the given locale", () => {
        expect(formatDateTime("2026-07-28T14:23:05+00:00", "de")).toBe("28.07.2026, 14:23:05");
    });

    it("converts the instant into the viewer's timezone", () => {
        // Same instant, two notations: both must render as the pinned zone's wall clock.
        expect(formatDateTime("2026-07-28T14:23:05Z", "de")).toBe("28.07.2026, 14:23:05");
        expect(formatDateTime("2026-07-28T16:23:05+02:00", "de")).toBe("28.07.2026, 14:23:05");
    });

    it("formats English structurally as a medium date plus a 12-hour clock", () => {
        // Asserted by shape, not literally: ICU moves the space before "PM" between versions.
        expect(formatDateTime("2026-07-28T14:23:05Z", "en")).toMatch(/^Jul 28, 2026, 2:23:05\s?PM$/u);
    });

    it("returns null for a missing or unparseable value instead of 'Invalid Date'", () => {
        expect(formatDateTime(null, "de")).toBeNull();
        expect(formatDateTime("", "de")).toBeNull();
        expect(formatDateTime("not a date", "de")).toBeNull();
    });
});

describe("formatDuration", () => {
    it("breaks a total down largest unit first", () => {
        const total = 30 * 86400 + 4 * 86400 + 7 * 3600 + 12 * 60 + 30;

        expect(formatDuration(total, unit)).toBe("1m, 4d, 7h, 12m, 30s");
    });

    it("drops leading zero units but keeps every unit below the first non-zero one", () => {
        expect(formatDuration(3661, unit)).toBe("1h, 1m, 1s");
        expect(formatDuration(61, unit)).toBe("1m, 1s");
        // An exact hour still carries its zeroed minutes and seconds.
        expect(formatDuration(3600, unit)).toBe("1h, 0m, 0s");
    });

    it("always shows at least seconds, so an empty total is not an empty string", () => {
        expect(formatDuration(0, unit)).toBe("0s");
    });

    it("uses flat 30-day months, so the parts sum back to the exact total", () => {
        // 30 days is a month, 29 is not — a duration has no calendar.
        expect(formatDuration(30 * 86400, unit)).toBe("1m, 0d, 0h, 0m, 0s");
        expect(formatDuration(29 * 86400, unit)).toBe("29d, 0h, 0m, 0s");
    });

    it("rounds a fractional total before breaking it down", () => {
        expect(formatDuration(59.6, unit)).toBe("1m, 0s");
    });

    it("passes each count to the caller's resolver for pluralisation", () => {
        const calls: [DurationUnit, number][] = [];

        formatDuration(3661, (key, count) => {
            calls.push([key, count]);

            return `${count} ${key}`;
        });

        expect(calls).toStrictEqual([
            ["hours", 1],
            ["minutes", 1],
            ["seconds", 1]
        ]);
    });
});

/*
 * The same breakdown with its seams left in, for the one caller that draws each unit as its own
 * unbreakable run (StatsWidget's playtime tile). The breakdown rules themselves are covered above —
 * what matters here is only that the split and the joined form cannot drift apart.
 */
describe("formatDurationParts", () => {
    it("returns the units separately rather than as one string", () => {
        expect(formatDurationParts(3661, unit)).toStrictEqual(["1h", "1m", "1s"]);
    });

    it("is what formatDuration joins, so neither can gain a unit the other lacks", () => {
        const total = 30 * 86400 + 4 * 86400 + 7 * 3600 + 12 * 60 + 30;

        expect(formatDurationParts(total, unit).join(", ")).toBe(formatDuration(total, unit));
    });

    it("carries the single-unit case as a one-element list, not an empty one", () => {
        expect(formatDurationParts(0, unit)).toStrictEqual(["0s"]);
    });
});

describe("formatPosition", () => {
    it("writes index/total when the index fits inside the set", () => {
        expect(formatPosition(2, 8)).toBe("2/8");
        expect(formatPosition(1, 1)).toBe("1/1");
        expect(formatPosition(8, 8)).toBe("8/8");
    });

    it("drops the denominator when the index runs past it", () => {
        // Multi-disc rips number straight through, so "17/8" would read as an app bug.
        expect(formatPosition(17, 8)).toBe("17");
    });

    it("falls back to the bare index when there is no total", () => {
        expect(formatPosition(3, null)).toBe("3");
    });

    it("returns null without an index, so the cell drops instead of reading 0", () => {
        expect(formatPosition(null, 8)).toBeNull();
        expect(formatPosition(null, null)).toBeNull();
    });
});

describe("formatTimesPlayed", () => {
    it("writes the count with a multiplication sign", () => {
        expect(formatTimesPlayed(34)).toBe("34\u00d7");
    });

    it("uses U+00D7, not the letter x", () => {
        // It sits beside real numbers in a tile and a table cell; an "x" reads as a typo
        // and sorts as a letter if anyone ever parses it back.
        expect(formatTimesPlayed(2)).not.toContain("x");
        expect(formatTimesPlayed(2).codePointAt(1)).toBe(0x00d7);
    });

    it("needs no plural rule, which is the whole reason it is a figure and not a sentence", () => {
        // German wants "einmal" where English wants "once"; "1\u00d7" is right in both.
        expect(formatTimesPlayed(1)).toBe("1\u00d7");
    });

    it("formats a zero rather than refusing it — hiding it is the caller's decision", () => {
        // The hero tiles omit a zero, the listings draw a dash. Neither rule belongs here.
        expect(formatTimesPlayed(0)).toBe("0\u00d7");
    });
});

describe("formatYearRange", () => {
    it("writes a span as two years joined by an en dash, unspaced", () => {
        expect(formatYearRange(1965, 2024)).toBe("1965\u20132024");
    });

    it("collapses a collection that spans a single year to that one year", () => {
        // Not "1994\u20131994", which reads as a range somebody forgot to fill in.
        expect(formatYearRange(1994, 1994)).toBe("1994");
    });

    it("never separates the thousands, which is the whole reason this is not formatDecimals", () => {
        // A year is not a quantity. Through the locale-aware formatter a German reader would be
        // shown "1.965\u20132.024", which reads as two large numbers rather than as a span. That is
        // also why this formatter takes no locale: there is nothing here for one to decide.
        expect(formatYearRange(1965, 2024)).not.toContain(".");
        expect(formatYearRange(1994, 1994)).not.toContain(".");
    });

    it("answers null when either end is unknown, so the caller can drop the tile", () => {
        // SQL's MIN/MAX skip untagged rows, so both ends are null for a collection whose rows
        // carry no year at all \u2014 and a dash with blanks either side is worse than one fewer fact.
        expect(formatYearRange(null, null)).toBeNull();
        expect(formatYearRange(1965, null)).toBeNull();
        expect(formatYearRange(null, 2024)).toBeNull();
    });
});
