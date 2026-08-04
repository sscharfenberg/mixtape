import { afterEach, describe, expect, it } from "vitest";
import { altKeyLabel, isApplePlatform, shortcut } from "./platform";

/*
 * The one thing worth testing here is the branch itself, because it decides a string a
 * reader is asked to act on: name the wrong key and the shortcut looks broken (which is
 * exactly what happened — the queue's hint said "Alt" to someone at a Mac keyboard).
 *
 * `navigator.platform` is read-only, so each case redefines it. Restored afterwards, or
 * every later file in the suite inherits a Mac.
 */
const original = { platform: navigator.platform, userAgent: navigator.userAgent };

/** Pretend to be a given machine. Either half may be empty — that is the point of one case. */
const pretend = (platform: string, userAgent = ""): void => {
    Object.defineProperty(window.navigator, "platform", { value: platform, configurable: true });
    Object.defineProperty(window.navigator, "userAgent", { value: userAgent, configurable: true });
};

describe("platform", () => {
    afterEach(() => pretend(original.platform, original.userAgent));

    it("recognises the Apple keyboards", () => {
        for (const platform of ["MacIntel", "iPhone", "iPad", "MacARM"]) {
            pretend(platform);
            expect(isApplePlatform()).toBe(true);
        }
    });

    it("recognises everything else", () => {
        for (const platform of ["Win32", "Linux x86_64", "Linux armv8l"]) {
            pretend(platform);
            expect(isApplePlatform()).toBe(false);
        }
    });

    it("falls back to the UA string when `platform` has been frozen away", () => {
        // The direction the deprecation is heading: a browser that reports an empty
        // `platform` must still be recognisable, or every Mac silently gets "Alt".
        pretend("", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15");

        expect(isApplePlatform()).toBe(true);
    });

    it("names the modifier the way the keyboard prints it", () => {
        pretend("MacIntel");
        expect(altKeyLabel()).toBe("⌥");

        pretend("Win32");
        expect(altKeyLabel()).toBe("Alt");
    });

    it("writes a chord the way each platform writes one", () => {
        // Apple runs the symbols together because ⌥ IS a symbol; elsewhere the modifier
        // is a word and needs the plus to read as one keystroke rather than two.
        pretend("MacIntel");
        expect(shortcut(altKeyLabel(), "↑/↓")).toBe("⌥↑/↓");

        pretend("Win32");
        expect(shortcut(altKeyLabel(), "↑/↓")).toBe("Alt+↑/↓");
    });
});
