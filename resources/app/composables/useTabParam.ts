/******************************************************************************
 * useTabParam
 * Keeps a TabbedNavigation selection in the URL's query string, so a reload, a
 * bookmark or a link shared with someone else reopens the tab the reader was on.
 * Bind the returned ref straight to `v-model:selected-tab`.
 *
 * The URL is rewritten IN PLACE with `history.replaceState` — deliberately NOT
 * an Inertia visit. Two reasons, and the second is the load-bearing one:
 *
 * 1. There is nothing to fetch. A tabbed page sends every panel's data at once
 *    (see ArtistController), so the server has no answer to a tab change that it
 *    has not already given.
 * 2. A visit would flash a loading state over content that is already on screen.
 *    DataTable raises its overlay on `router.on("start")` — for ANY navigation,
 *    not just its own — so routing a tab click through Inertia would grey out a
 *    table nobody asked to reload. `replaceState` never touches the network.
 *
 * `replace` rather than `push` is the other half of that: a tab is a view of one
 * page, not a destination, so flipping between them five times must not put five
 * entries between the reader and wherever they came from. The trade is that Back
 * leaves the page instead of stepping through tabs — the right way round for a
 * control that changes nothing but which half of the page you are looking at.
 *
 * `history.state` is carried through the rewrite untouched: Inertia keeps the
 * current page object there, and replacing it with null would break the back
 * button for the whole app rather than just for this strip.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { getCurrentScope, onScopeDispose, ref, watch } from "vue";

/** Return type of the {@link useTabParam} composable. */
export type UseTabParamReturn = {
    /** The open tab's id, or undefined when the URL names none — bind to `v-model:selected-tab`. */
    tab: Ref<string | undefined>;
};

/**
 * Mirror a tab selection into `?<param>=` and read it back on load.
 *
 * @param param Query key to store it under. Override it for a page carrying a second strip,
 *              since one key cannot describe both.
 */
export function useTabParam(param = "tab"): UseTabParamReturn {
    /** The param's current value, or undefined when absent or empty (`?tab=` names nothing). */
    const readFromUrl = (): string | undefined =>
        new URLSearchParams(window.location.search).get(param) || undefined;

    const tab = ref<string | undefined>(readFromUrl());

    // Write-through. Nothing is written on load, so a reader who never touched the tabs keeps
    // a clean URL and the strip just shows its default.
    watch(tab, value => {
        const url = new URL(window.location.href);
        if (value === undefined) url.searchParams.delete(param);
        else url.searchParams.set(param, value);

        const next = `${url.pathname}${url.search}${url.hash}`;
        if (next === `${window.location.pathname}${window.location.search}${window.location.hash}`) return;
        history.replaceState(history.state, "", next);
    });

    // Re-read after an Inertia navigation, which is what makes Back work across a table that
    // sorts inside a tab: DataTable carries the whole query string over, so `tab` survives its
    // visits — and stepping back to an earlier one has to put the strip where that URL says.
    const stopListening = router.on("navigate", () => {
        tab.value = readFromUrl();
    });
    if (getCurrentScope()) onScopeDispose(stopListening);

    return { tab };
}
