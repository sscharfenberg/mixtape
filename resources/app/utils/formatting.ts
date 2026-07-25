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
 * @param value                 the number to format
 * @param locale                BCP-47 locale tag (the app's active locale)
 * @param maximumFractionDigits most decimal places to show (default 0)
 */
export const formatDecimals = (value: number, locale: string, maximumFractionDigits = 0): string =>
    new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value);

/**
 * Humanise a byte count to a locale-formatted size string: GB at or above 1 GiB,
 * otherwise MB — both binary (1024-based) units, rounded to one decimal.
 *
 * @param bytes  size in bytes
 * @param locale BCP-47 locale tag, for the number's separators
 */
export const formatFileSize = (bytes: number, locale: string): string =>
    bytes >= 1024 ** 3
        ? `${formatDecimals(bytes / 1024 ** 3, locale, 1)} GB`
        : `${formatDecimals(bytes / 1024 ** 2, locale, 1)} MB`;

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
export const formatDuration = (
    totalSeconds: number,
    unit: (key: DurationUnit, count: number) => string
): string => {
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
