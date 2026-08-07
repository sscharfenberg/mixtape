import { flushPromises } from "@vue/test-utils";
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { getI18n, loadLocaleMessages, setI18nLanguage, setupI18n } from "@/i18n";
import de from "@/lang/de.json";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import LanguageSwitch from "./LanguageSwitch.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The language picker. It switches the UI OPTIMISTICALLY — no Inertia visit, no reload — and
 * only afterwards tells the server, which makes the ORDER of its four steps the thing worth
 * pinning. Get it wrong and the symptoms are all subtle:
 *
 *   - the catalog is loaded BEFORE the locale flips. Flip first and vue-i18n re-renders
 *     against messages that have not arrived, so the whole app blinks through raw keys.
 *   - the POST is FIRE-AND-FORGET, after the UI has already changed. Awaiting it before
 *     switching would make the language wait on a round trip for a change the client could
 *     make instantly; skipping it entirely loses the preference on the next page load.
 *   - re-picking the ACTIVE locale is a no-op. Without that guard every press of the current
 *     language re-fetches a catalog and posts again — and, worse, closes the menu, so the
 *     control appears to do something while doing nothing.
 *
 * The endonyms are deliberately NOT translated: a language is offered in its own name, so a
 * reader stranded in a language they cannot read can still find theirs. Running them through
 * `t()` is the obvious "fix" that breaks exactly that.
 *
 * That the choice survives a reload is the server's half (ConfigureLocale + the users.locale
 * column) and belongs to its feature test.
 *
 * One artefact of the harness to know before reading the assertions: the component switches
 * the i18n SINGLETON (`getI18n()`), while `mountApp` gives the component tree its own
 * instance. In the app those are the same object; here they are not, so what a switch is
 * observable through is the singleton's own side effects — `<html lang>` and the POST — not
 * the mounted markup re-rendering.
 */

/** Requests the switch made. */
let fetchMock: ReturnType<typeof vi.fn>;

/** Mount the picker over the supported locales. */
const picker = (locales = ["de", "en"], locale: "de" | "en" = "de") => {
    setPage({ props: { supportedLocales: locales, csrfToken: "token" } });

    return mountApp(LanguageSwitch, { locale, attachTo: document.body });
};

describe("LanguageSwitch", () => {
    beforeAll(async () => {
        // The switch reaches the i18n SINGLETON directly (it has to set `<html lang>` and
        // load a catalog, neither of which is component state), so one has to exist.
        setupI18n({ legacy: false, locale: "de", messages: { de } });

        /*
         * Warm the English catalog. `onSelect` opens with a DYNAMIC IMPORT, and a cold one
         * does not settle within `flushPromises` — so without this the rest of the handler
         * (the `<html lang>` write and the POST) runs after the test has finished, landing on
         * the NEXT test's fetch mock. That presents as the no-op test seeing a request it
         * never made, which points nowhere near the cause.
         */
        await loadLocaleMessages(getI18n(), "en");
        setI18nLanguage(getI18n(), "de");
    });

    beforeEach(() => {
        resetInertia();
        setI18nLanguage(getI18n(), "de");
        fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: () => Promise.resolve({}) });
        vi.stubGlobal("fetch", fetchMock);
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("offers one entry per locale the server supports, and no more", () => {
        expect(picker(["de"]).findAll("button")).toHaveLength(1);
        expect(picker(["de", "en"]).findAll("button")).toHaveLength(2);
    });

    it("names each language in its own words, so a stranded reader can still find theirs", () => {
        // Not run through t(): "Deutsch" stays "Deutsch" for an English reader.
        expect(
            picker(["de", "en"], "en")
                .findAll("button")
                .map(button => button.text())
        ).toStrictEqual(["Deutsch", "English"]);
    });

    it("marks the active locale, both visually and for a screen reader", () => {
        const buttons = picker(["de", "en"], "en").findAll("button");

        expect(buttons[0].attributes("aria-current")).toBeUndefined();
        expect(buttons[1].attributes("aria-current")).toBe("true");
        expect(buttons[1].classes()).toContain("popover-list-item--selected");
    });

    it("switches the document's language and persists the choice, closing the menu around it", async () => {
        const wrapper = picker(["de", "en"], "de");

        await wrapper.findAll("button")[1].trigger("click");
        /*
         * `vi.waitFor`, not `flushPromises`: the handler opens with a DYNAMIC IMPORT, which
         * does not settle inside one promise flush. Asserting too early is not merely a
         * failed expectation here — the rest of the handler then runs after teardown, so the
         * POST lands on the NEXT test's fetch mock and that test fails instead of this one.
         */
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

        // `<html lang>` matters beyond tidiness: it is what a screen reader picks its voice
        // and pronunciation from, and what CSS `:lang()` hyphenation keys off.
        expect(document.documentElement.getAttribute("lang")).toBe("en");
        expect(wrapper.emitted("close")).toHaveLength(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/lang/en");
        expect(init.method).toBe("POST");
        expect((init.headers as Record<string, string>)["X-CSRF-TOKEN"]).toBe("token");
    });

    it("does nothing at all when the language already in force is picked again", async () => {
        // No re-fetch, no second POST — and crucially no `close`, which would make the
        // control look like it did something.
        const wrapper = picker(["de", "en"], "de");

        await wrapper.findAll("button")[0].trigger("click");
        // One flush is enough here precisely BECAUSE the guard returns before the first
        // await — if anything had started, this would be too early and the test would be
        // asserting a race rather than the guard.
        await flushPromises();

        expect(wrapper.emitted("close")).toBeUndefined();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("gives every entry a flag whose alt text is empty, since the name is right beside it", () => {
        const flags = picker(["de", "en"]).findAll("img.flag");

        expect(flags).toHaveLength(2);
        expect(flags.every(flag => flag.attributes("alt") === "")).toBe(true);
    });
});
