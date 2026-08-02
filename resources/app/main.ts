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
 * Router events — view transitions + progress bar
 * The progress bar is @sscharfenberg/progressbar, driven by Inertia's router
 * events (styled by styles/components/progress/_progress-bar.scss). Start is
 * delayed 250ms so a fast visit never flashes a bar. The bar is position:fixed,
 * so appending it to #app (the Inertia root) is immaterial.
 *
 * (The breadcrumb trail used to be cleared here on "start". It isn't any more —
 * it travels as an Inertia layout prop, which Inertia resets at the component
 * swap instead. See Composables/useBreadcrumbs.)
 *****************************************************************************/
let progressTimeout: ReturnType<typeof setTimeout> | undefined;

/**
 * True for the visits that must not paint any page-level chrome, because from the
 * reader's point of view nothing is happening: a partial reload
 * (`router.reload({ only: … })`) refreshes a few props of the page already on
 * screen, and a hover prefetch is not a navigation at all — nobody has clicked.
 *
 * Inertia fires the same `before` / `start` / `finish` events for both, which is
 * the trap: leave prefetches in and resting the pointer on a link raises a
 * full-page progress bar for a page the reader may never open.
 */
const isBackgroundVisit = (event: { detail: { visit: { only: string[]; prefetch: boolean } } }): boolean =>
    event.detail.visit.only.length > 0 || event.detail.visit.prefetch;

/**
 * Opt every real navigation into the View Transitions API, so the outgoing page
 * stays on screen and cross-fades into the incoming one instead of being
 * replaced in a single frame. Set here rather than as a `view-transition` prop on
 * all ~48 <Link>s in the app: it is a property of *navigating*, not of any one
 * link, and DataTable's clickable rows go through `router.visit` with no <Link>
 * at all. Inertia reads `visit.viewTransition` after firing this event, and falls
 * back to a plain swap wherever `document.startViewTransition` is missing.
 *
 * Skipped for partial reloads and state-preserving visits (a sort, a filter, a
 * tab switch): those refresh part of a page that is staying put, and DataTable
 * already draws its own loading overlay for them. Prefetches never swap anything.
 *
 * Gated on `prefers-reduced-motion: no-preference` — motion is opt-in in this app
 * (see CLAUDE.md → Motion), and unlike the CSS transitions this one can only be
 * refused in JS: the cross-fade is generated by the browser, not by a rule we own.
 */
router.on("before", event => {
    const visit = event.detail.visit;
    if (visit.preserveState || isBackgroundVisit(event)) return;

    visit.viewTransition = window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
});

router.on("start", event => {
    if (isBackgroundVisit(event)) return;
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
    if (isBackgroundVisit(event)) return;
    clearTimeout(progressTimeout);
    if (doesProgressBarExist() && event.detail.visit.completed) finishProgress();
    else if (event.detail.visit.interrupted) setProgress(0);
    else if (event.detail.visit.cancelled) finishProgress();
});
