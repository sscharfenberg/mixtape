/******************************************************************************
 * resolvePage
 * Turn an Inertia page name ("NowPlaying/NowPlayingPage") into the component that renders it.
 *
 * ITS OWN MODULE RATHER THAN A CLOSURE IN main.ts, for one reason: main.ts calls
 * `createInertiaApp` at import time, so nothing in it can be unit-tested without mounting the
 * whole app. The rule below is worth a test, so it lives where a test can reach it.
 *****************************************************************************/
import type { DefineComponent } from "vue";

/**
 * Load a page component by Inertia's name for it, and switch off attribute fallthrough.
 *
 * NO ATTRIBUTE FALLTHROUGH ON A PAGE, and doing it here is the point — this is the one seam every
 * page in the app comes through, so a new page cannot forget it and twenty-odd files do not each
 * need a `defineOptions`.
 *
 * Inertia hands every page the SHARED props as well as its own — `csrfToken`, `locale`, `auth`,
 * `features`, `library`, `playlists`, `player`, `playerState`, `flash` — and a page declares only
 * what it uses, so the rest arrive as fallthrough attrs. None of them is an HTML attribute and
 * none should ever reach a DOM node.
 *
 * Every page here renders a FRAGMENT (a `<Head>` beside a `<container>`, usually a `<headline>`
 * too), so Vue cannot inherit those attrs onto a single root and warns about it once per render —
 * in development only, since production strips `[Vue warn]` entirely. Harmless in the DOM either
 * way (measured: nothing lands on any element), and unreadable on the Now Playing page, which
 * re-renders on every track change and turns one stale line into a stream.
 *
 * Verified safe before switching it off: nothing under `pages/` reads `$attrs` or `useAttrs()`.
 *
 * @param name Inertia's page name, without the `pages/` prefix or the `.vue` suffix
 */
export async function resolvePage(name: string): Promise<DefineComponent> {
    const pages = import.meta.glob<{ default: DefineComponent }>("./pages/**/*.vue");
    const pageLoader = pages[`./pages/${name}.vue`];

    if (!pageLoader) {
        throw new Error(`Page not found: ${name}`);
    }

    const page = await pageLoader();

    page.default.inheritAttrs = false;

    return page.default;
}
