<script setup lang="ts">
/******************************************************************************
 * SearchOverlay
 * The header's search: a panel that opens under the header with the field focused, mounted ONCE
 * in FullLayout beside the play queue.
 *
 * NOT AN INPUT THAT LIVES IN THE HEADER. At phone width the header is already logo + title +
 * four buttons, and a field wedged in there is what makes a header wrap. So the trigger is one
 * more round glyph (SearchToggle) and the field arrives when asked for.
 *
 * IN FullLayout RATHER THAN IN AppHeader, which is the decision that keeps the guest space clean:
 * ShareLayout renders the same header and deliberately mounts no search — "a share grants one
 * subject, and a library search on that page would be an invitation to a login form". Mounting it
 * inside the header would put it in both layouts, and the alternative fix, a condition in the
 * header ("am I in ShareLayout?"), is a copy of the layout's decision that eventually disagrees
 * with it. Instead this registers itself (`noteSearchOverlay`) and the trigger and the keys read
 * that — the same arrangement PlayQueue and its toggle arrived at.
 *
 * IT LIGHT-DISMISSES, because it is a native `[popover]` like the queue panel and the menus. That
 * one attribute buys three behaviours consistently: a click anywhere outside closes it, Escape
 * closes it, and on Android the back gesture closes it instead of leaving the page. Two
 * consequences worth knowing rather than discovering — opening another auto popover (the site
 * menu, the queue) closes this one, since that is what light dismiss means for two popovers that
 * are not nested; and the flag in useSearchOverlay is therefore a MIRROR of the element as much
 * as a command to it, which is what `@toggle` below is for.
 *
 * CLOSING FORGETS THE QUERY. A box that reopened with last week's question and last week's rows
 * would be showing an answer nobody asked for — and, on a library the scanner keeps changing, an
 * answer that may no longer be true. Reopening is always a fresh question.
 *
 * NOTHING AT ALL FOR A GUEST, which is the second half of the same rule and needs stating because
 * this layout is the app-wide default: the LOGIN page renders in it too, so a `/search` behind
 * `auth` would otherwise be offered to somebody who can only be redirected to the form they are
 * looking at. It is watched rather than decided once, because signing in is an Inertia visit — the
 * layout instance survives it, so a check that ran only on mount would leave a freshly signed-in
 * reader without the feature until their next full page load.
 *
 * The two keys are bound while there is something for them to open — the same scoping that lets `Q`
 * live in the player's keymap. useSearchOverlay carries the check that neither can collide with the
 * player's shortcuts.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import SearchField from "Components/Search/SearchField.vue";
import SearchResults from "Components/Search/SearchResults.vue";
import SearchScopeChips from "Components/Search/SearchScopeChips.vue";
import { useLibrarySearch } from "Composables/useLibrarySearch";
import { bindSearchShortcuts, noteSearchOverlay, unbindSearchShortcuts, useSearchOverlay } from "Composables/useSearchOverlay";

const { t } = useI18n();
const { isOpen, close, setOpen, focusNonce } = useSearchOverlay();

// Opening a result puts the panel away: the reader has gone somewhere, and a dropdown still
// hanging over the page they asked for is a dropdown they have to dismiss.
//
// Destructured so the template sees unwrapped refs — `search.query.value` in a template works and
// reads like plumbing.
const { query, scope, groups, loading, failed, active, tooShort, listboxId, activeOptionId, onKeydown, clear } =
    useLibrarySearch({ onNavigate: close });

const page = usePage();

/** The signed-in reader, or null for a guest — the whole feature's precondition. */
const user = computed(() => page.props.auth.user);

/**
 * TELL THE HEADER WHETHER THERE IS SOMETHING TO OPEN, and bind or drop the two keys with it.
 *
 * One function for both signals below, because they are the same statement: an overlay exists here
 * when this component is mounted AND somebody is signed in.
 */
function announce(present: boolean): void {
    noteSearchOverlay(present);
    if (present) bindSearchShortcuts();
    else unbindSearchShortcuts();
}

// On MOUNT rather than in setup, and dropped on unmount, so a layout swap lands right way up —
// noteSearchOverlay explains both orderings.
onMounted(() => announce(Boolean(user.value)));

// …and again whenever the reader signs in or out under a layout that stays mounted.
watch(user, signedIn => announce(Boolean(signedIn)));

onUnmounted(() => announce(false));

/** The popover element itself, so its state can be driven and read. */
const layer = useTemplateRef<HTMLElement>("layer");

/** The field, so opening the panel can put the caret in it. */
const field = useTemplateRef<InstanceType<typeof SearchField>>("field");

/**
 * Keep the element and the shared flag in step, in both directions.
 *
 * DOWN: the header's trigger writes the flag and this shows or hides the popover to match.
 * Guarded on the element's own `:popover-open`, because `showPopover()` on a popover that is
 * already showing — and `hidePopover()` on one that is not — both THROW, and the mirror below
 * means this regularly runs when the element is already where it should be.
 *
 * UP: `handleToggle` adopts whatever the browser did on its own, which is the half that makes
 * light dismiss more than a visual — without it the flag would still read "open" after a click
 * outside, and the header would keep offering a close glyph for a panel that is gone.
 */
function apply(open: boolean): void {
    const element = layer.value;
    if (!element) return;

    const showing = element.matches(":popover-open");
    if (open && !showing) element.showPopover();
    if (!open && showing) element.hidePopover();
}

/**
 * Adopt the element's state — fired for a light dismiss, Escape, the back gesture, and our own
 * calls. A close also forgets the question, so the next opening starts clean (see the banner).
 */
function handleToggle(event: ToggleEvent): void {
    const open = event.newState === "open";
    if (!open) clear();
    setOpen(open);
}

watch(isOpen, apply);

/**
 * Put the caret in the field.
 *
 * `flush: "post"` on both watchers below, because a popover that is still `display: none` cannot
 * take focus — the DOM has to have been updated and the element promoted to the top layer first.
 * That is also why the shortcuts only ask rather than focusing themselves: "open it" and "put the
 * caret in it" are one gesture for the reader and two frames for the browser.
 */
function focusField(): void {
    field.value?.focus();
}

// The panel just opened — including when something other than a deliberate request opened it.
watch(isOpen, open => (open ? focusField() : undefined), { flush: "post" });

/*
 * …AND WHENEVER SEARCH IS ASKED FOR, which is the case the flag above cannot see: press ⌘K (or the
 * header's glyph, or `/`) while the panel is ALREADY open and `isOpen` does not change, so nothing
 * fired and the caret stayed wherever it was — measured on a breadcrumb link after tabbing out of an
 * open panel. A request is an event rather than a state, so it travels as a nonce (useSearchOverlay).
 */
watch(focusNonce, focusField, { flush: "post" });
</script>

<template>
    <div v-if="user" ref="layer" class="search-layer" popover="auto" @toggle="handleToggle">
        <section class="search-panel" :aria-label="t('search.label')">
            <!-- The one child that keeps an inset, since the panel has none: a field welded to
                 the panel's edges would read as part of the frame. -->
            <search-field
                ref="field"
                class="search-panel__field"
                v-model="query"
                :listbox-id="listboxId"
                :active-option-id="activeOptionId"
                :expanded="groups.length > 0"
                :loading="loading"
                @keydown="onKeydown"
            />
            <!-- The scope chips, WITH the results rather than above an empty field: six of them
                 are a row of noise in a panel nobody has typed into yet, and narrowing is only a
                 question once there is something to narrow.

                 The same control the Music page mounts — the two surfaces are one feature and
                 have no business behaving differently — with a `name`
                 of its own: two radiogroups sharing one would form a single group, and choosing in
                 the second would silently clear the first. Both can be on the page at once, since
                 the overlay is mounted on /music too. -->
            <search-scope-chips
                v-if="active"
                v-model="scope"
                name="overlay-search-scope"
                class="search-panel__chips"
            />

            <!-- Nothing at all until there is a question: an empty overlay showing "type
                 something" is a panel explaining itself to somebody who has just opened it. -->
            <search-results
                v-if="active"
                :groups="groups"
                :listbox-id="listboxId"
                :active-option-id="activeOptionId"
                :loading="loading"
                :failed="failed"
                :too-short="tooShort"
                @navigate="close"
            />
        </section>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* THE LAYER is what makes the panel line up, and it is why there is an element here that draws
   nothing. The panel has to end exactly where the app's content cage ends — the same line the
   header's inner row finishes on — and the obvious way to say that, `right: max(0px, (100vw -
   cage) / 2)`, is wrong by half the scrollbar: `100vw` counts it, the cage does not.

   A FIXED element's containing block is the layout viewport, which EXCLUDES the scrollbar. So a
   fixed layer with `left: 0; right: 0; max-width: cage; margin-inline: auto` centres itself on
   exactly the same line Container does, and the panel simply pins to its trailing edge — under
   the trigger that opened it. No viewport arithmetic anywhere. (The queue panel arrived at this
   first; it is the same trick and the same reason.)

   It hangs from the header (`--app-header-height`, published by AppHeader) and passes clicks
   through, since it is a coordinate system rather than a surface — which is also what lets a
   click beside the panel light-dismiss it. No z-index token: a showing `[popover]` is in the top
   layer, above every rung in the app's scale by definition. */
.search-layer {
    position: fixed;

    /* HEADER TO THE BOTTOM OF THE WINDOW, and the bottom half is load-bearing rather than tidy: an
       `auto` bottom leaves the layer ZERO HIGH, since its only child is absolutely positioned — and
       a zero-high box with the UA's `overflow: auto` (see below) CLIPS that child. The panel then
       still reports a bounding box, so it looks present to a test and is unreachable to a pointer:
       every click on a row landed on the header or on <main> instead. Measured as
       "…intercepts pointer events" on three specs. The layer passes clicks through anyway, so
       spanning the window costs nothing. */
    inset: var(--app-header-height, 0) 0 0 0;

    box-sizing: border-box;

    /* THE UA SHEET'S `[popover]` DEFAULTS, NEUTRALISED. A popover ships as a centred,
       content-sized, bordered box (`width/height: fit-content; margin: auto; border: solid;
       padding: 0.25em; overflow: auto; background: Canvas`), which is right for a menu and wrong
       for a coordinate system. `width: auto` matters most: left at `fit-content` it would beat
       the `inset` above and the layer would hug the panel instead of spanning the page; and
       `overflow: visible` is what stops the panel being clipped to whatever the layer measures. */
    overflow: visible;

    width: auto;
    max-width: map.get(s.$c-app, "max");
    height: auto;
    padding: 0;
    border: 0;

    background: none;
    color: inherit;

    /* NO `display` HERE, deliberately. The UA hides `[popover]` until `:popover-open`, and an
       author `display` — of either value — beats that and pins the panel permanently open or
       permanently shut. */

    pointer-events: none;
    margin-block: 0;
    margin-inline: auto;

    /* The layer never moves; these two exist only so the PANEL survives its own exit. Closing a
       popover yanks it out of the top layer and sets `display: none` on the same frame, which
       cuts the transition off mid-gesture — `allow-discrete` holds both until it has finished.
       Declared here rather than under `:popover-open`, because a discrete transition has to be
       described in the state being left as well as the one being entered. */
    @media (prefers-reduced-motion: no-preference) {
        transition:
            display ti.$c-search allow-discrete,
            overlay ti.$c-search allow-discrete;
    }
}

/* Open. `@starting-style` is what gives the panel a from-value at all: while the popover is shut
   it is not merely hidden but NOT RENDERED, so without it the first style the panel ever has is
   the finished one and nothing transitions. The exit needs no equivalent — the base rule below is
   the from-value going back. */
.search-layer:popover-open .search-panel {
    @media (prefers-reduced-motion: no-preference) {
        opacity: 1;

        transform: translateY(0);
    }
}

/* The panel, pinned to the layer's trailing edge under the trigger, and inset from it by the
   app's own padding so it lines up with page content rather than with the window. */
.search-panel {
    display: flex;
    position: absolute;
    inset: 0 map.get(s.$c-app, "padding", "desktop") auto auto;
    flex-direction: column;

    box-sizing: border-box;

    /* Full width up to its cap: on a phone that is the screen, on a monitor it is a readable
       column rather than a 1440px dropdown with five words in it. */
    width: calc(100% - #{map.get(s.$c-app, "padding", "desktop")} * 2);
    max-width: map.get(s.$c-search, "panel-max");

    /* BLOCK PADDING ONLY, which is what lets the results run edge to edge: a full-width heading
       strip is a divider where an inset band is just another block of content, and the scroll
       container's scrollbar then lands on the panel's own inner edge instead of floating a step
       inside it (the owner's report). The field puts its own inset back with a margin below; the
       rows and the strips carry theirs as padding, from the same token, so every line of text in
       the panel still starts on one vertical. */
    padding-block: map.get(s.$c-search, "padding");

    /* NO TOP BORDER. The panel hangs directly off the header, which already draws a bottom edge —
       two lines a pixel apart read as a seam rather than as a frame. The same reasoning the queue
       panel's `border-inline`-only rule records for the edges it meets. */
    border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "border");
    border-top: 0;

    gap: map.get(s.$c-search, "gap");

    background-color: map.get(c.$c-search, "background");
    color: map.get(c.$c-search, "surface");

    border-radius: 0 0 map.get(s.$c-search, "radius") map.get(s.$c-search, "radius");

    pointer-events: auto;

    /* The field and the chips keep the inset the panel gave up — the results deliberately do not,
       because their strips and stripes are meant to run edge to edge. */
    &__field,
    &__chips {
        margin-inline: map.get(s.$c-search, "padding");
    }

    /* A short drop, not a slide: the panel is already where it belongs and only announces its
       arrival. The distance is small on purpose — this opens often (two keystrokes from
       anywhere), and a long journey is tiring by the tenth time. */
    @media (prefers-reduced-motion: no-preference) {
        opacity: 0;

        transform: translateY(-0.5rem);

        transition:
            opacity ti.$c-search ease-out,
            transform ti.$c-search ease-out;
    }
}
</style>
