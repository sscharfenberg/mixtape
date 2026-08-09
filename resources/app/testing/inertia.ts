/******************************************************************************
 * A stand-in for @inertiajs/vue3, for unit tests.
 *
 * Why a whole fake module rather than a provider: Inertia's `usePage()` reads a
 * MODULE-LEVEL page ref owned by the router (see @inertiajs/vue3 dist — `usePage`
 * closes over `page`, it does not `inject`). Nothing is passed through the
 * component tree, so there is no plugin or provide/inject seam a test could use to
 * hand a component its props. Mocking the module is the only honest way in.
 *
 * Use it from a test file with:
 *
 * ```ts
 * vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));
 * ```
 *
 * The exports below mirror everything the app actually imports from the real
 * package — Link, Head, usePage, router, Form and setLayoutProps (verified by
 * grepping resources/app). Anything else a component reaches for will fail loudly as an
 * undefined import rather than silently rendering nothing, which is the intent:
 * a new Inertia dependency should force a decision here.
 *****************************************************************************/
import { defineComponent, h, reactive } from "vue";
import type { PropType } from "vue";

/** One recorded router call, so a test can assert what a click actually asked for. */
export type RouterCall = {
    /** Router method invoked, e.g. "visit" or "reload". */
    method: string;
    /** First argument — the URL for visit/get/post, or the options object for reload. */
    url?: string;
    /**
     * The BODY, for the verbs that carry one. Recorded because some calls are only
     * meaningful together with what they sent: "add to playlist" posts either a subject or a
     * list of track ids to the same URL, so the URL alone does not say what happened.
     */
    data?: unknown;
    /** The options bag, when one was passed. */
    options?: Record<string, unknown>;
};

/** Inertia event name the app subscribes to via `router.on(...)`. */
export type RouterEvent = "start" | "finish" | "navigate" | "success" | "error";

/** The page object `usePage()` resolves to. Reactive, so watchers in components fire on change. */
const page = reactive<{ props: Record<string, unknown>; url: string; component: string }>({
    props: {},
    url: "/",
    component: ""
});

/** Every router call made since the last reset, oldest first. */
export const routerCalls: RouterCall[] = [];

/**
 * The layout-prop store, standing in for the one inside @inertiajs/vue3. Merges
 * on write and is emptied by {@link resetInertia}, which is where the real
 * package's reset happens too (inside `swapComponent`, i.e. between pages).
 * Reactive so a component holding it re-renders, and readable so a test can
 * assert what a page published — useBreadcrumbs writes the trail through here.
 */
const layoutProps = reactive<Record<string, unknown>>({});

/** Live `router.on` subscribers, keyed by event — driven by {@link emitRouterEvent}. */
const listeners = new Map<RouterEvent, Set<(payload?: unknown) => void>>();

/**
 * Point `usePage()` at a page object. Call it in `beforeEach` before mounting
 * anything that reads page props (csrfToken, auth, flash, locale...).
 *
 * Merges into the existing props rather than replacing the object identity, so a
 * component already holding the reactive page sees the update.
 */
export const setPage = (next: { props?: Record<string, unknown>; url?: string; component?: string }): void => {
    if (next.props) Object.assign(page.props, next.props);
    if (next.url !== undefined) page.url = next.url;
    if (next.component !== undefined) page.component = next.component;
};

/**
 * Clear page props, recorded calls and subscribers. Because this module is a
 * singleton shared by every test in a file, skipping this leaks one test's state
 * into the next — call it from `beforeEach`.
 */
export const resetInertia = (): void => {
    for (const key of Object.keys(page.props)) delete page.props[key];
    for (const key of Object.keys(layoutProps)) delete layoutProps[key];
    page.url = "/";
    page.component = "";
    routerCalls.length = 0;
    listeners.clear();
};

/** `setLayoutProps()` — merges into the store, exactly as the real one does. */
export const setLayoutProps = (props: Record<string, unknown>): void => {
    Object.assign(layoutProps, props);
};

/** Read what the page under test published to its layout — the assertion side of setLayoutProps. */
export const getLayoutProps = (): Record<string, unknown> => layoutProps;

/**
 * Fire an Inertia router event at everything currently subscribed, so a test can
 * simulate a navigation without a network. useTabParam re-reads the URL on
 * "navigate"; DataTable raises its overlay on "start".
 */
export const emitRouterEvent = (event: RouterEvent, payload?: unknown): void => {
    listeners.get(event)?.forEach(handler => handler(payload));
};

/**
 * Record a router call and hand back a resolved promise, matching the real API's shape.
 *
 * `data` is left OFF the record when the call carried none, rather than stored as undefined:
 * several specs compare a recorded call with `toStrictEqual`, which counts an undefined key as
 * a difference — so an always-present field would fail every one of them for a body that was
 * never sent.
 */
const record = (method: string, url?: string, options?: Record<string, unknown>, data?: unknown): void => {
    routerCalls.push(data === undefined ? { method, url, options } : { method, url, data, options });
};

/** The mock router. `on()` returns its own unsubscribe, as the real one does. */
export const router = {
    visit: (url: string, options?: Record<string, unknown>) => record("visit", url, options),
    get: (url: string, data?: unknown, options?: Record<string, unknown>) => record("get", url, options, data),
    post: (url: string, data?: unknown, options?: Record<string, unknown>) => record("post", url, options, data),
    put: (url: string, data?: unknown, options?: Record<string, unknown>) => record("put", url, options, data),
    patch: (url: string, data?: unknown, options?: Record<string, unknown>) => record("patch", url, options, data),
    delete: (url: string, options?: Record<string, unknown>) => record("delete", url, options),
    reload: (options?: Record<string, unknown>) => record("reload", undefined, options),
    prefetch: (url: string, options?: Record<string, unknown>) => record("prefetch", url, options),
    on: (event: RouterEvent, handler: (payload?: unknown) => void): (() => void) => {
        if (!listeners.has(event)) listeners.set(event, new Set());
        listeners.get(event)!.add(handler);

        return () => listeners.get(event)?.delete(handler);
    }
};

/** `usePage()` — returns the shared reactive page object, exactly as the real one does. */
export const usePage = () => page;

/**
 * `<Link>` as a plain anchor. Rendering a real <a href> (rather than a bare stub)
 * is deliberate: it keeps `getByRole("link", { name })` working in tests, and it
 * keeps rowNavigation's "a click that landed on a link is not row navigation"
 * rule honest — a stub with no anchor would let that guard pass vacuously.
 */
export const Link = defineComponent({
    // The EXPORT has to be called Link (that is what components import); the internal
    // name is only debug metadata, so it avoids the reserved HTML element name.
    name: "InertiaLink",
    props: {
        href: { type: String, default: "" },
        method: { type: String, default: "get" },
        as: { type: String, default: "a" },
        // Declared purely to absorb it: the real Link consumes `prefetch` as a
        // prop, so leaving it undeclared here would spread it onto the <a> as a
        // stray attribute and make it visible to DOM assertions.
        prefetch: { type: [Boolean, String, Array], default: false }
    },
    setup: (props, { slots, attrs }) => () => h(props.as, { ...attrs, href: props.href }, slots.default?.()),
    inheritAttrs: false
});

/**
 * `<Head>` renders nothing. The real one teleports into document.head; a test that
 * cares about the title should assert the prop passed to the page, not the DOM.
 */
export const Head = defineComponent({
    name: "InertiaHead",
    props: { title: { type: String, default: "" } },
    setup: () => () => null
});

/**
 * `<Form>` renders a real <form> and exposes the slot props the pages destructure
 * (`{ errors, valid, invalid, validating, validate, processing }` — see
 * DashboardProfile). Submitting records a router call so a test can assert the
 * action/method without a network round-trip.
 */
export const Form = defineComponent({
    name: "InertiaForm",
    props: {
        action: { type: String, default: "" },
        method: { type: String, default: "post" },
        // Errors a test wants the form to render, keyed by field name.
        errors: { type: Object as PropType<Record<string, string>>, default: () => ({}) }
    },
    setup(props, { slots, attrs }) {
        const onSubmit = (event: Event): void => {
            event.preventDefault();
            record(props.method, props.action);
        };

        return () =>
            h("form", { ...attrs, onSubmit }, [
                slots.default?.({
                    errors: props.errors,
                    valid: (field: string) => !props.errors[field],
                    invalid: (field: string) => Boolean(props.errors[field]),
                    validating: false,
                    validate: () => {},
                    processing: false
                })
            ]);
    },
    inheritAttrs: false
});

/** Not used by tests, but imported by main.ts — present so the mock module stays a drop-in. */
export const createInertiaApp = (): void => {};
