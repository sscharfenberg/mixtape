/******************************************************************************
 * i18n
 * vue-i18n setup, ported from cantrip.me. The app runs in composition mode
 * (`legacy: false`), so the active locale lives on `i18n.global.locale` as a
 * Ref and all `$t()` / `t()` calls re-render when it changes. Message catalogs
 * are NOT bundled up front: only the active locale's JSON is dynamically
 * imported (Vite code-splits one chunk per locale), so the initial download
 * stays small. The server ships only the locale string (Inertia share); the
 * matching catalog is loaded here. A module-level singleton lets non-component
 * code (e.g. the language switcher) reach the instance without prop-drilling.
 *****************************************************************************/
import { nextTick } from "vue";
import { createI18n } from "vue-i18n";
import type { Composer, I18n, I18nOptions, Locale } from "vue-i18n";
import type deMessages from "@/lang/de.json";

/** Shape of a locale's catalog — mirrors the German source, the schema authority (see types/i18n.d.ts). */
type LocaleMessageSchema = typeof deMessages;

/** Module-level reference kept so callers can retrieve the instance without prop-drilling. */
let i18nInstance: I18n | null = null;

/**
 * Return the module-level i18n instance.
 * Throws if called before {@link setupI18n} (i.e. before the app is mounted).
 */
export function getI18n(): I18n {
    if (!i18nInstance) {
        throw new Error("i18n has not been initialized. Call setupI18n first.");
    }
    return i18nInstance;
}

/**
 * Write the active locale on an i18n instance. In composition mode the locale
 * is a `Ref<string>` on `i18n.global`, hence the cast + `.value` assignment.
 */
export function setLocale(i18n: I18n, locale: Locale): void {
    (i18n.global as unknown as Composer).locale.value = locale;
}

/**
 * Create and configure the global i18n instance, storing it at module level for
 * later retrieval via {@link getI18n}. Called once during bootstrap in main.ts.
 */
export function setupI18n(options: I18nOptions = { locale: "de" }): I18n {
    const i18n = createI18n(options);
    i18nInstance = i18n;
    setI18nLanguage(i18n, options.locale!);
    return i18n;
}

/** Activate a locale and mirror it onto the `<html lang>` attribute. */
export function setI18nLanguage(i18n: I18n, locale: Locale): void {
    setLocale(i18n, locale);
    document.querySelector("html")!.setAttribute("lang", locale);
}

/** Unwrap an ESM module's default export (vs. a namespace object) as a typed catalog. */
const getResourceMessages = (r: { default?: LocaleMessageSchema }): LocaleMessageSchema =>
    r.default ?? (r as unknown as LocaleMessageSchema);

/**
 * Dynamically import a locale's catalog and register it on the instance.
 * Loaded on demand so only the active locale's JSON is fetched at startup.
 * Returns a `nextTick` promise so callers can await DOM stabilization (e.g.
 * before mounting the app).
 */
export async function loadLocaleMessages(i18n: I18n, locale: Locale) {
    const messages = await import(`./lang/${locale}.json`).then(getResourceMessages);
    i18n.global.setLocaleMessage(locale, messages);

    return nextTick();
}
