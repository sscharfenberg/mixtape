/******************************************************************************
 * Main app entrypoint
 *****************************************************************************/
import "@/styles/app.scss";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { doesProgressBarExist, finishProgress, setProgress, startProgress } from "@sscharfenberg/progressbar";
import type { DefineComponent } from "vue";
import { createApp, h } from "vue";
import type { Composer } from "vue-i18n";
import { getI18n, loadLocaleMessages, setupI18n } from "@/i18n";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import FullLayout from "./components/Layout/FullLayout.vue";
import { vTooltip } from "./directives/vTooltip";

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

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n);

        // Global because a tooltip is a cross-cutting affordance — registering it
        // per SFC would mean importing it in nearly every component. Template
        // types come from the GlobalDirectives augmentation in
        // resources/types/directives.d.ts.
        app.directive("tooltip", vTooltip);

        // Defer mount until the active locale's catalog is loaded, so the first
        // paint never shows raw translation keys.
        loadLocaleMessages(i18n, initialLocale).then(() => app.mount(el));
    },
    title: title => (title ? `${appName}: ${title}` : appName),
    progress: false // disable Inertia's built-in NProgress — we drive our own bar below
});

/******************************************************************************
 * Router events — progress bar + breadcrumb reset
 * The progress bar is @sscharfenberg/progressbar, driven by Inertia's router
 * events (styled by styles/components/progress/_progress-bar.scss). Start is
 * delayed 250ms so a fast visit never flashes a bar; partial reloads
 * (router.reload({ only: … })) are skipped so an in-place prop refresh doesn't
 * show a full-page bar. The bar is position:fixed, so appending it to #app (the
 * Inertia root) is immaterial.
 *****************************************************************************/
let progressTimeout: ReturnType<typeof setTimeout> | undefined;

/** True for Inertia partial reloads (a subset of props refreshed in place, not a navigation). */
const isPartialReload = (event: { detail: { visit: { only: string[] } } }): boolean =>
    event.detail.visit.only.length > 0;

router.on("start", event => {
    // Clear the breadcrumb trail as a navigation begins, so the incoming page's
    // own setBreadcrumbs() call is the only thing that can fill it — a page that
    // declares none shows none, instead of inheriting the previous page's path.
    // Skipped when the visit preserves state (a sort / filter / reload on the
    // same page): there the component isn't re-created, so nothing would ever
    // put the trail back.
    if (!event.detail.visit.preserveState) {
        useBreadcrumbs().setBreadcrumbs([]);
    }

    if (isPartialReload(event)) return;
    // ariaLabel resolved lazily — i18n is initialised (in createInertiaApp's
    // setup) before any navigation can fire.
    const ariaLabel = (getI18n().global as unknown as Composer).t("common.loadingProgress");
    progressTimeout = setTimeout(() => startProgress({ ariaLabel, parent: "#app" }), 250);
});

router.on("progress", event => {
    if (doesProgressBarExist() && event.detail.progress?.percentage) {
        // Cap at 90% while in flight; finishProgress() completes it on "finish".
        setProgress((event.detail.progress.percentage / 100) * 0.9);
    }
});

router.on("finish", event => {
    if (isPartialReload(event)) return;
    clearTimeout(progressTimeout);
    if (doesProgressBarExist() && event.detail.visit.completed) finishProgress();
    else if (event.detail.visit.interrupted) setProgress(0);
    else if (event.detail.visit.cancelled) finishProgress();
});
