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
 * A total-seconds duration as a human breakdown, e.g. "1 month, 4 days, 7 hours,
 * 12 minutes, 30 seconds". Uses flat 30-day months (a duration has no calendar),
 * so the parts always sum back to the exact total; leading zero units are dropped
 * (everything from the first non-zero unit down to seconds is kept, so at least
 * "0 seconds" always shows).
 *
 * i18n is left to the caller: `unit(key, count)` must return one already-localised,
 * pluralised part like "7 hours" / "7 Stunden", so this helper needs no translation
 * dependency of its own.
 *
 * @param totalSeconds total duration in seconds
 * @param unit         resolves a unit key + count to a localised, pluralised label
 */
export const formatDuration = (totalSeconds: number, unit: (key: DurationUnit, count: number) => string): string => {
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

    return shown.map(([key, value]) => unit(key, value)).join(", ");
};
