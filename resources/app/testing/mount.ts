/******************************************************************************
 * Mount helper for component and page tests.
 *
 * Every component in this app assumes two things exist: a vue-i18n instance (t()
 * is called in nearly every template) and the global `v-tooltip` directive that
 * main.ts registers. Re-declaring both in each test file is noise, and getting
 * either subtly wrong — a stub `t` that echoes the key, say — hides real bugs.
 *
 * The catalogs here are the REAL de.json / en.json, imported statically. That is
 * the load-bearing choice: a test asserting rendered German text fails when a key
 * is renamed or dropped, which is precisely the regression an echo-the-key stub
 * would sail straight past. It also means these tests double as a check that the
 * keys components ask for actually exist.
 *****************************************************************************/
import { mount } from "@vue/test-utils";
import type { ComponentMountingOptions } from "@vue/test-utils";
import type { Component } from "vue";
import { createI18n } from "vue-i18n";
import { vTooltip } from "@/directives/vTooltip";
import de from "@/lang/de.json";
import en from "@/lang/en.json";

/** The app's supported locales, as the mount helper accepts them. */
export type TestLocale = "de" | "en";

/**
 * Build an i18n instance over the real catalogs.
 *
 * `legacy: false` matches main.ts (composition mode), and both catalogs are
 * registered up front — the app lazy-loads them per locale, but a test wants the
 * locale switch to be synchronous.
 *
 * `missingWarn` / `fallbackWarn` stay ON deliberately: a component asking for a key
 * that does not exist should be noisy in the test output, not silently rendered as
 * the raw key.
 */
export const createTestI18n = (locale: TestLocale = "de") =>
    createI18n({
        legacy: false,
        locale,
        fallbackLocale: locale === "de" ? "en" : "de",
        messages: { de, en }
    });

/** Extra knobs on top of @vue/test-utils' own mounting options. */
export type MountAppOptions<C extends Component> = ComponentMountingOptions<C> & {
    /** Locale to render in. Defaults to "de", the app's default. */
    locale?: TestLocale;
};

/**
 * Mount a component with the app's real i18n and the global tooltip directive.
 *
 * Anything else — Inertia's Link/Head/usePage — comes from the module mock, which a
 * test file opts into itself (`vi.mock("@inertiajs/vue3", () => import("Testing/inertia"))`).
 * That is left to the caller rather than done here because `vi.mock` is hoisted per
 * file and cannot be applied from inside a helper.
 *
 * @param component the component under test
 * @param options   @vue/test-utils options, plus an optional `locale`
 */
export const mountApp = <C extends Component>(component: C, options: MountAppOptions<C> = {}) => {
    const { locale = "de", global: globalOptions, ...rest } = options;

    return mount(component, {
        ...rest,
        global: {
            ...globalOptions,
            plugins: [createTestI18n(locale), ...(globalOptions?.plugins ?? [])],
            directives: { tooltip: vTooltip, ...globalOptions?.directives },
            stubs: { ...globalOptions?.stubs }
        }
    } as ComponentMountingOptions<C>);
};

/**
 * The sprite symbol names of every `<Icon>` rendered inside `wrapper`, in document order.
 *
 * Exists because reading them by hand is a trap: Icon's template writes `xlink:href`, but
 * the DOM exposes that namespaced attribute under its LOCAL name, so
 * `attributes("xlink:href")` comes back undefined while `attributes("href")` works. Every
 * test that checks which icon rendered would otherwise re-learn that.
 */
export const iconNames = (wrapper: { findAll: (selector: string) => { attributes: (name: string) => string | undefined }[] }): string[] =>
    wrapper.findAll("use").map(node => (node.attributes("href") ?? "").replace(/^#/u, ""));

/**
 * Look up a translation the same way a component would, for building expectations
 * without hard-coding German strings into every assertion.
 *
 * Use it when the *identity* of the string is what matters (this heading shows the
 * songs label) rather than its exact wording. Where the wording itself is the point,
 * assert the literal instead — otherwise the test is just re-running the lookup and
 * would pass even if the catalog entry were wrong.
 */
export const translate = (key: string, locale: TestLocale = "de"): string => {
    const catalog = (locale === "de" ? de : en) as Record<string, unknown>;

    const resolved = key.split(".").reduce<unknown>((node, part) => {
        if (node && typeof node === "object") return (node as Record<string, unknown>)[part];

        return undefined;
    }, catalog);

    if (typeof resolved !== "string") throw new Error(`Missing or non-string i18n key: ${key}`);

    return resolved;
};
