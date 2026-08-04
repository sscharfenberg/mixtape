/******************************************************************************
 * platform
 * Which keyboard the reader is sitting at — and nothing else.
 *
 * It exists because a shortcut has to be NAMED for the machine it is pressed on.
 * The modifier bit that arrives as `event.altKey` is printed "Alt" on a PC
 * keyboard and ⌥ on an Apple one, so a hint that says "Alt" is, on a Mac, naming
 * a key the reader cannot find — worse than saying nothing. That is the whole job
 * here: the WORDS, not the behaviour.
 *
 * Deliberately NOT a general "am I on a Mac" switch. Behaviour must never branch
 * on the platform — that is what feature detection and CSS are for, and the
 * handler itself keeps reading `event.altKey`, which is the same bit on every
 * keyboard. Use this only where a key is being named to a human.
 *****************************************************************************/

/**
 * True on macOS, iPadOS and iOS.
 *
 * `navigator.platform` is deprecated, but it is still the one string every
 * browser agrees on and returns synchronously — `navigator.userAgentData` is
 * Chromium-only and its `platform` needs an async call for anything more. The UA
 * string is folded in as the fallback for a browser that has frozen or emptied
 * `platform`, which is the direction the deprecation is heading. A false negative
 * costs a Mac reader the ⌥ symbol and nothing else, so a cheap sniff is the right
 * amount of effort.
 */
export const isApplePlatform = (): boolean => {
    const source = `${navigator.platform ?? ""} ${navigator.userAgent ?? ""}`;

    return /mac|iphone|ipad|ipod/iu.test(source);
};

/** The Alt / Option modifier as this keyboard prints it: ⌥ on an Apple one, "Alt" elsewhere. */
export const altKeyLabel = (): string => (isApplePlatform() ? "⌥" : "Alt");

/**
 * Write "hold this, press that" the way the platform writes it.
 *
 * Apple's own convention runs the symbols together (⌥↑) because ⌥ *is* a symbol;
 * everywhere else the modifier is a word and needs the plus to read as one
 * chord (Alt+↑). One helper rather than two hard-coded strings per shortcut, so
 * the convention lives in a single place.
 */
export const shortcut = (modifier: string, key: string): string =>
    isApplePlatform() ? `${modifier}${key}` : `${modifier}+${key}`;
