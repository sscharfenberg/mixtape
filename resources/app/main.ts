/******************************************************************************
 * Main app entrypoint
 *****************************************************************************/
import "@/styles/app.scss";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { doesProgressBarExist, finishProgress, setProgress, startProgress } from "@sscharfenberg/progressbar";
import { createApp, h } from "vue";
import type { Composer } from "vue-i18n";
import { getI18n, loadLocaleMessages, setupI18n } from "@/i18n";
import FullLayout from "./components/Layout/FullLayout.vue";
import { vTooltip } from "./directives/vTooltip";
import { resolvePage } from "./resolvePage";

// Single source of truth: APP_NAME in .env, mirrored to the frontend via VITE_APP_NAME.
const appName = import.meta.env.VITE_APP_NAME;

/******************************************************************************
 * mount Inertia App
 *****************************************************************************/
createInertiaApp({
    resolve: resolvePage,
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
 * THE PAIR THAT DRIVES IT IS `before` → `finish` / `navigate`, NOT `start` →
 * `finish`, and the reason is prefetching: `start` does not fire for a visit
 * Inertia serves from a prefetch, and `finish` does not fire for one served from
 * a completed prefetch. Both halves are argued where they are wired, with the
 * measured event sequences (armProgress, and the `navigate` note at the bottom).
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
    if (isBackgroundVisit(event)) return;

    // The bar is armed for EVERY foreground visit, including the state-preserving ones
    // the view transition below skips: a sort or a filter still makes the reader wait on
    // the server, which is the only thing this bar is about. (DataTable draws its own
    // overlay for those as well; a table that dims while the bar runs is one wait
    // announced twice, not two waits.)
    armProgress();

    const visit = event.detail.visit;
    if (visit.preserveState) return;

    visit.viewTransition = window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
});

/**
 * Arm the bar for a visit the reader is waiting on, after a 250ms grace period so a
 * fast one never flashes.
 *
 * ON `before` RATHER THAN ON `start`, AND THAT IS THE WHOLE FIX (2026-08-12). `start`
 * does not fire for a visit that Inertia serves from a PREFETCH — and prefetching is
 * what most links in this app do on hover (LabelledLink, Breadcrumb, Discography). The
 * three cases, measured:
 *
 *   - no prefetch (a click that outran the hover timer): before → start → finish
 *   - prefetch IN FLIGHT when the click lands:           before → finish → navigate
 *   - prefetch already complete (a cache hit):           before → navigate
 *
 * So the bar was missing in exactly the case it exists for. The middle one is a real
 * wait — measured at 1.2 seconds here — and it is the one a reader describes as
 * "nothing happens, then the page switches": the click is parked on an in-flight
 * request nobody told them about. The cache hit is instant and correctly shows
 * nothing, but only because the grace period expires first.
 *
 * `before` is the only event all three share. It fires for prefetches and partial
 * reloads too, which is what `isBackgroundVisit` is still for.
 */
function armProgress(): void {
    // Cleared first, so a second visit starting before the first was taken down cannot
    // leave a timer nothing owns.
    clearTimeout(progressTimeout);
    // ariaLabel resolved lazily — i18n is initialised (in createInertiaApp's
    // setup) before any navigation can fire.
    const ariaLabel = (getI18n().global as unknown as Composer).t("common.loadingProgress");
    progressTimeout = setTimeout(() => startProgress({ ariaLabel, parent: "#app" }), 250);
}

/** Take the bar down, whether it was drawn yet or is still inside its grace period. */
function disarmProgress(): void {
    clearTimeout(progressTimeout);
    if (doesProgressBarExist()) finishProgress();
}

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

/*
 * THE BACKSTOP, and it is not belt-and-braces — it is the only thing that ends the bar
 * for a visit served from a completed prefetch, where `finish` NEVER FIRES at all
 * (measured; see armProgress for the three event sequences). Without it, arming on
 * `before` would leave a click on an already-warmed link raising a bar 250ms later that
 * nothing ever takes down.
 *
 * `navigate` fires once the incoming page component is on screen, which is exactly when
 * the reader has what they were waiting for. It carries no `visit` in its detail, so
 * there is nothing to filter on and nothing to filter — taking down a bar that was never
 * drawn is a no-op, and `finish` above still owns the interrupted and cancelled cases,
 * which never reach here.
 */
router.on("navigate", disarmProgress);
