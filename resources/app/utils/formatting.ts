/**
 * Number, size and duration formatting helpers. Pure functions (no state, no Vue
 * reactivity): the caller passes the active locale, and — for durations — an i18n
 * label resolver, so these stay usable anywhere and trivial to test. This module
 * is the home for such formatting; add new number/size/date/string formatters here
 * rather than inlining them in components.
 */

/** A unit in a duration breakdown, largest → smallest. */
export type DurationUnit = "months" | "days" | "hours" | "minutes" | "seconds";

/**
 * Format a number with the given locale's grouping and decimal separators
 * (e.g. de → "1.234,5", en → "1,234.5"). Defaults to whole numbers — thousands
 * separators, no fraction; raise `maximumFractionDigits` to show decimals.
 *
 * `minimumFractionDigits` PADS to that many places ("1,4" → "1,40"), which a
 * maximum alone never does. Sizes want it (see formatFileSize) so a column of them
 * lines up under `tabular-nums`; a plain count does not, hence the 0 default.
 *
 * @param value                 the number to format
 * @param locale                BCP-47 locale tag (the app's active locale)
 * @param maximumFractionDigits most decimal places to show (default 0)
 * @param minimumFractionDigits fewest decimal places to show, zero-padded (default 0)
 */
export const formatDecimals = (
    value: number,
    locale: string,
    maximumFractionDigits = 0,
    minimumFractionDigits = 0
): string => new Intl.NumberFormat(locale, { maximumFractionDigits, minimumFractionDigits }).format(value);

/** Decimal places on a file size — fixed, so "1,40 MB" and "12,00 MB" align digit for digit. */
const SIZE_DECIMALS = 2;

/**
 * Humanise a byte count to a locale-formatted size string: GB at or above 1 GiB,
 * otherwise MB — both binary (1024-based) units, always to two decimals.
 *
 * The two places are FIXED rather than a maximum: these sizes are read in stacked
 * rows (the song page's file card, the collection's stats tile), and a ragged
 * "1,4 MB" over "12 MB" reads as sloppier data than it is.
 *
 * @param bytes  size in bytes
 * @param locale BCP-47 locale tag, for the number's separators
 */
export const formatFileSize = (bytes: number, locale: string): string =>
    bytes >= 1024 ** 3
        ? `${formatDecimals(bytes / 1024 ** 3, locale, SIZE_DECIMALS, SIZE_DECIMALS)} GB`
        : `${formatDecimals(bytes / 1024 ** 2, locale, SIZE_DECIMALS, SIZE_DECIMALS)} MB`;

/**
 * A track length in seconds as a clock string — `m:ss`, or `h:mm:ss` once past an
 * hour. Null in, null out, so a file that carried no duration drops its row/cell
 * instead of reading "0:00".
 *
 * Takes no locale: a colon-separated clock is written the same way in every
 * language this app speaks, which is exactly why it lives client-side with the
 * other formatters instead of being pre-rendered by the server. It is shared by
 * the Songs listing and the song detail page — two callers formatting seconds
 * separately is how the two drift apart.
 *
 * @param seconds playing time in seconds (fractional is fine — it is rounded)
 */
export const formatClock = (seconds: number | null): string | null => {
    if (seconds === null || !Number.isFinite(seconds)) return null;

    const total = Math.round(seconds);
    const pad = (value: number) => String(value).padStart(2, "0");
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(total % 60)}` : `${minutes}:${pad(total % 60)}`;
};

/**
 * An ISO-8601 timestamp as a locale-formatted date + time, to the second
 * (de → "28.07.2026, 14:23:05", en → "Jul 28, 2026, 2:23:05 PM").
 *
 * The server sends UTC instants with their offset, so `Date` parsing and
 * `Intl.DateTimeFormat` together render them in the *viewer's* timezone — which is
 * what a file's mtime should read as, no matter where the server sits. Returns
 * null for a missing or unparseable value, so a caller can drop the row rather
 * than print "Invalid Date".
 *
 * @param iso    ISO-8601 timestamp (with offset), or null
 * @param locale BCP-47 locale tag (the app's active locale)
 */
export const formatDateTime = (iso: string | null, locale: string): string | null => {
    if (!iso) return null;

    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) return null;

    return new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "medium" }).format(parsed);
};

/**
 * A CALENDAR DAY — `"2026-08-16"` — as a full locale date ("Sonntag, 16. August 2026").
 *
 * A separate helper from {@link formatDateTime} because a day is not an instant, and treating
 * it as one is how a heading ends up naming the wrong date: `new Date("2026-08-16")` parses as
 * UTC MIDNIGHT, which west of Greenwich is still the 15th, so the label would disagree with
 * the rows underneath it for every reader in the Americas. Splitting the string and handing
 * the parts to the local-time constructor keeps the day the server grouped by.
 *
 * `dateStyle: "full"` rather than "long": the weekday is the half a reader recognises a day
 * by — "last Saturday" is how anyone remembers an evening's listening, where a number is
 * something they have to work out.
 *
 * @param date   a calendar day as `YYYY-MM-DD`, or null
 * @param locale BCP-47 locale tag (the app's active locale)
 */
export const formatDay = (date: string | null, locale: string): string | null => {
    if (!date) return null;

    const parts = date.split("-").map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) return null;

    const [year, month, day] = parts;

    return new Intl.DateTimeFormat(locale, { dateStyle: "full" }).format(new Date(year, month - 1, day));
};

/**
 * An ISO-8601 instant as a bare clock in the reader's own timezone ("21:34", "9:34 PM").
 *
 * The time WITHOUT the date, for a list that already says which day it is: repeating
 * "16.08.2026" down forty rows under a heading that says the same thing is noise, and it is
 * the minutes that tell one listen from the next. Callers that need the whole instant — a
 * tooltip, an accessible name — ask {@link formatDateTime} for it instead.
 *
 * @param iso    ISO-8601 timestamp (with offset), or null
 * @param locale BCP-47 locale tag (the app's active locale)
 */
export const formatTimeOfDay = (iso: string | null, locale: string): string | null => {
    if (!iso) return null;

    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) return null;

    return new Intl.DateTimeFormat(locale, { timeStyle: "short" }).format(parsed);
};

/**
 * A total-seconds duration as its separate human parts — ["1 month", "4 days", "7 hours",
 * "12 minutes", "30 seconds"]. Uses flat 30-day months (a duration has no calendar), so the parts
 * always sum back to the exact total; leading zero units are dropped (everything from the first
 * non-zero unit down to seconds is kept, so at least "0 seconds" always shows).
 *
 * i18n is left to the caller: `unit(key, count)` must return one already-localised, pluralised part
 * like "7 hours" / "7 Stunden", so this helper needs no translation dependency of its own.
 *
 * SPLIT OUT OF {@link formatDuration} rather than replacing it, because a caller that draws the
 * breakdown needs the SEAMS: StatsWidget makes each part unbreakable so a line can only break
 * between them, and a joined string has nothing to hang that on. Everywhere the breakdown is one
 * run of text (the playlist and share heroes) still asks for the string.
 *
 * @param totalSeconds total duration in seconds
 * @param unit         resolves a unit key + count to a localised, pluralised label
 */
export const formatDurationParts = (
    totalSeconds: number,
    unit: (key: DurationUnit, count: number) => string
): string[] => {
    const MIN = 60;
    const HOUR = 60 * MIN;
    const DAY = 24 * HOUR;
    const MONTH = 30 * DAY;

    let rem = Math.round(totalSeconds);
    const parts: [DurationUnit, number][] = [
        ["months", Math.floor(rem / MONTH)],
        ["days", Math.floor((rem %= MONTH) / DAY)],
        ["hours", Math.floor((rem %= DAY) / HOUR)],
        ["minutes", Math.floor((rem %= HOUR) / MIN)],
        ["seconds", rem % MIN]
    ];

    const firstNonZero = parts.findIndex(([, value]) => value > 0);
    const shown = firstNonZero === -1 ? parts.slice(-1) : parts.slice(firstNonZero);

    return shown.map(([key, value]) => unit(key, value));
};

/**
 * The same breakdown as one string, e.g. "1 month, 4 days, 7 hours, 12 minutes, 30 seconds".
 *
 * The comma-space join is the only thing this adds over {@link formatDurationParts}, and it is kept
 * as its own function because three of the four callers want exactly that and should not each
 * re-decide the separator.
 *
 * @param totalSeconds total duration in seconds
 * @param unit         resolves a unit key + count to a localised, pluralised label
 */
export const formatDuration = (totalSeconds: number, unit: (key: DurationUnit, count: number) => string): string =>
    formatDurationParts(totalSeconds, unit).join(", ");

/**
 * A position within its set, as "2/8" — or the bare number when there is no
 * trustworthy total.
 *
 * The denominator is DROPPED when the index runs past it, because some rips number
 * tracks straight through a multi-disc set and a track can legitimately sit past its
 * own disc's count. "17/8" would read as a bug in the app rather than as sloppy tags,
 * so it degrades to "17".
 *
 * Null in, null out, so a file with no disc or track number drops its cell instead of
 * reading "0". Shared by the song page's facts and the artist page's songs table, so
 * the two can never disagree about how a "1/1" is written.
 *
 * @param index the position, e.g. the track number
 * @param total the size of the set it sits in, e.g. how many tracks the disc holds
 */
export const formatPosition = (index: number | null, total: number | null): string | null => {
    if (index === null) return null;

    return total !== null && index <= total ? `${index}/${total}` : `${index}`;
};

/**
 * A count of listens, e.g. "34×".
 *
 * `×` is U+00D7, not the letter x — this is a count of times and it sits beside real
 * numbers. Locale-independent on purpose: the sign carries the meaning in both languages,
 * where a sentence would have wanted "einmal" for "1" in one of them and "once" in the
 * other. That is what lets the same string serve a hero tile and a table cell.
 *
 * Shared by PlayCountFacts and the three listings' plays column, so the hero and the table
 * can never disagree about how a play count is written. Neither of them prints a bare zero
 * — the tiles hide, the tables draw a dash — but that is each one's own display decision,
 * so it is not baked in here.
 *
 * @param count how many listens, already counted by the server
 */
export const formatTimesPlayed = (count: number): string => `${count}×`;

/**
 * Two years as one range — "1965–2024", or a single year for a collection that spans none.
 *
 * Null when neither end is known, which is a real answer rather than a fallback: a range with a
 * dash and nothing either side is worse than one fewer fact, so the caller drops the tile.
 *
 * AN EN DASH WITHOUT SPACES, the typographic form for a span of years in both catalogues.
 *
 * NOT `formatDecimals`, unlike every count that sits beside it, and this is the whole reason the
 * range is a formatter of its own: a year is not a quantity, so a German locale would render 1994
 * as "1.994". That is also why it takes no locale — there is nothing here for one to decide.
 *
 * Shared by the music and audiobook stats cards, so the two can never disagree about how a span
 * of years is written.
 *
 * @param firstYear the oldest year anything in the collection carries, or null when none does
 * @param lastYear the newest, likewise
 */
export const formatYearRange = (firstYear: number | null, lastYear: number | null): string | null => {
    if (firstYear === null || lastYear === null) return null;

    return firstYear === lastYear ? String(firstYear) : `${firstYear}–${lastYear}`;
};
