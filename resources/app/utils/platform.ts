/******************************************************************************
 * platform
 * How a keyboard shortcut is NAMED to a reader — and nothing else.
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
 *
 * `withKey` is the platform-independent half of the same job: the shape a hint
 * takes once the key has a name. It lives here so that "how a shortcut is
 * written" is one file rather than a convention re-derived per component.
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
 * The "command" modifier as this keyboard prints it: ⌘ on an Apple one, "Ctrl" elsewhere.
 *
 * NOT quite the same shape as its Alt sibling above, and the difference is the point: those are
 * two names for ONE bit, while this is two names for two different keys — the handler that reads
 * it accepts `metaKey || ctrlKey`, because a PC keyboard has no Cmd and a Mac's Ctrl is not where
 * a chord like this belongs. So this names whichever of the two the reader actually has, and the
 * behaviour stays platform-blind exactly as this file's banner insists.
 */
export const commandKeyLabel = (): string => (isApplePlatform() ? "⌘" : "Ctrl");

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

/**
 * Name a control and the key that works it: "Nächster Titel (⇧→)".
 *
 * The two halves come from the catalog separately because only the first is a sentence a
 * translator should be rewriting — "⇧→" and "Q" are the same on every keyboard this app speaks
 * to. Joining them here rather than storing combined strings means the parenthesis shape is
 * written once, and giving a control a key hint is a call site rather than a new catalog entry.
 *
 * A plain function, not a computed: it takes arguments, and `t` is reactive, so a template
 * re-renders these on a locale switch anyway.
 */
export const withKey = (label: string, key: string): string => `${label} (${key})`;
