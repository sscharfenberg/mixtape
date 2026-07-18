/******************************************************************************
 * Main app entrypoint
 *****************************************************************************/
import "@/styles/app.scss";
import { createInertiaApp } from "@inertiajs/vue3";
import type { DefineComponent } from "vue";
import { createApp, h } from "vue";
import { loadLocaleMessages, setupI18n } from "@/i18n";
import FullLayout from "./components/Layout/FullLayout.vue";

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;

/******************************************************************************
 * mount Inertia App
 *****************************************************************************/
createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob<DefineComponent>("./pages/**/*.vue");
        const pageLoader = pages[`./pages/${name}.vue`];
        if (!pageLoader) {
            throw new Error(`Page not found: ${name}`);
        }

        return pageLoader();
    },
    layout: () => FullLayout,
    setup({ el, App, props, plugin }) {
        // The server (ConfigureLocale → Inertia share) picks the active locale;
        // supportedLocales drives the fallback. Both arrive as initial page props.
        const { locale, supportedLocales } = props.initialPage.props as {
            locale?: string;
            supportedLocales?: string[];
        };
        const initialLocale = locale ?? "de";
        const availableLocales = supportedLocales ?? ["de"];

        const i18n = setupI18n({
            legacy: false,
            locale: initialLocale,
            // Fall back to any other supported locale (the primary "de" catalog is
            // the most complete), so a missing key still renders something.
            fallbackLocale: availableLocales.filter(l => l !== initialLocale)[0] ?? "de",
            messages: {}
        });

        const app = createApp({ render: () => h(App, props) }).use(plugin).use(i18n);

        // Defer mount until the active locale's catalog is loaded, so the first
        // paint never shows raw translation keys.
        loadLocaleMessages(i18n, initialLocale).then(() => app.mount(el));
    },
    title: title => (title ? `${appName}: ${title}` : appName),
    progress: { color: "#4f46e5" }
});
