<script setup lang="ts">
/******************************************************************************
 * PlaylistsPage
 * The Playlists area, reached at /playlists (route `playlists`, behind auth) and
 * linked from the header site menu (useSiteAreas). Lists the reader's OWN saved
 * playlists over a primary link to the create form.
 *
 * The create link sits ABOVE the list rather than at its end, because the empty
 * state is the normal first visit: a new account has no playlists, and the one
 * action the page offers should not be below a paragraph explaining why the page
 * is blank. It stays in the same place once the list fills, so it never moves.
 *
 * AN ENTRY IS A SMALL HERO — the detail pages' panel, at list scale: the same inset,
 * corner and slowly rotating gradient ring (tokens copied into `*.$c-playlist`, see the
 * styles). Its title is painted the hero's way too, and has been since both stopped wearing the
 * app's synthwave chrome: filled with the card's own colour and defined by a stroke,
 * so the name reads as cut out of the entry. Each ring starts at a different point in the same
 * turn, so a column of them drifts rather than pulsing in unison; the phase comes from
 * `--playlist-index`, published per row below.
 *
 * THE WHOLE ENTRY IS THE LINK, which is why the markup looks the way it does. An <a>
 * cannot wrap the panel — it may not contain the grip or the menu, both interactive —
 * so it covers it with a stretched `::after` instead, and the two real controls are
 * lifted back above that overlay. The anchor's accessible name therefore stays just the
 * title, rather than the whole row read aloud.
 *
 * Every value arrives raw — a plain count, seconds, ISO-8601 instants — and is
 * formatted here against the VIEWER's locale and timezone, which the server cannot
 * know. Two facts are conditional, and on facts about the data rather than about
 * formatting: a playlist with no description shows none, and `updatedAt` is null
 * until something actually changes, so an untouched playlist carries no "changed"
 * tile at all.
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { useTemplateRef } from "vue";
import { useI18n } from "vue-i18n";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { PlaylistEntry } from "Types/playlists";
import { formatDateTime, formatDuration } from "Utils/formatting";
import PlaylistMenu from "./PlaylistMenu.vue";
import { usePlaylistReorder } from "./usePlaylistReorder";

const props = defineProps<{
    /** The reader's own playlists, in their own order — empty for a fresh account. */
    playlists: PlaylistEntry[];
}>();

/**
 * The listing element, and the order the page actually renders.
 *
 * `entries` rather than the prop directly: a drag has to show before the server has agreed
 * to it, and an Inertia prop cannot be written. usePlaylistReorder owns that copy, keeps it
 * seeded from the prop, and persists whatever the reader does to it.
 */
const list = useTemplateRef<HTMLUListElement>("list");
const { entries, onEntryKeydown, shortcutLabel } = usePlaylistReorder(list, () => props.playlists);

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "header.siteMenu.playlists", icon: "playlist" }]);

/**
 * An ISO-8601 instant in the reader's locale and timezone.
 *
 * Returns "" rather than null for a missing or unparseable one, because that is what
 * FactPair's caller contract wants: an empty value is a tile the caller should not
 * render, and `v-if` on "" reads the same as on null without a second type.
 */
const dateOf = (iso: string | null): string => formatDateTime(iso, locale.value) ?? "";

/**
 * How long a playlist plays, as a human breakdown ("1 Stunde, 12 Minuten").
 *
 * `formatDuration` rather than `formatClock`: a total is read as an amount of time, not
 * as a position on a timeline, and it grows an hours part on its own for a long playlist
 * while still saying plain minutes for a short one. The same call StatsWidget makes for
 * the collection's playtime, so the two agree.
 *
 * Empty for a playlist with nothing in it — the server sends null there (SUM over no
 * rows), and "0 Sekunden" beside a track count of 0 says nothing twice.
 */
const playtimeOf = (seconds: number | null): string =>
    seconds === null || seconds === 0 ? "" : formatDuration(seconds, (key, count) => t(`common.duration.${key}`, count));
</script>

<template>
    <Head :title="t('header.siteMenu.playlists')" />
    <headline glow>
        <icon name="playlist" :size="3" />
        {{ t("header.siteMenu.playlists") }}
    </headline>

    <container>
        <p class="playlists__actions">
            <Link href="/playlists/create" class="btn btn-primary">
                <icon name="playlist" :size="1" />
                <span>{{ t("playlists.createLink") }}</span>
            </Link>
        </p>

        <ul v-if="entries.length" ref="list" class="playlist__list">
            <!-- `--playlist-index` is the row's position in the list, and the ONLY thing
                 this template knows about the look: the ring's animation reads it as a
                 negative delay, so every entry sits at its own point in the same turn.
                 Published from here rather than derived in CSS because `:nth-child` can
                 only carry a fixed set of phases, and a listing has as many entries as the
                 reader has playlists. -->
            <li
                v-for="(playlist, index) in entries"
                :key="playlist.id"
                class="playlist"
                :style="{ '--playlist-index': index }"
                @keydown="onEntryKeydown($event, index)"
            >
                <!-- The reorder grip: the only thing a drag starts from, and the tab stop the
                     keyboard alternative hangs off. Pressing it does nothing on its own — the
                     drag is a pointer gesture and Alt+↑/↓ is the keyboard one — which is the
                     honest shape of a handle, and the same shape the play queue's grip has.
                     THE HINT SAYS "CLICK IT FIRST" because it has to: Alt+↑/↓ moves the FOCUSED
                     entry, so hovering one and pressing the keys does nothing at all, which is
                     exactly how it reads as broken. `aria-keyshortcuts` keeps ARIA's canonical
                     spelling while the tooltip names the key this keyboard actually prints. -->
                <button
                    type="button"
                    class="playlist__handle"
                    v-tooltip="t('playlists.reorderHint', { keys: shortcutLabel })"
                    :aria-label="t('playlists.reorder', { name: playlist.name })"
                    aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown"
                >
                    <icon name="drag" :size="1" />
                </button>

                <!-- The playlist's own page. A real Inertia <Link> now that there is
                     somewhere to go, so the visit is client-side like every other
                     navigation; `prefetch` warms it on hover. -->
                <Link class="playlist__link" :href="`/playlists/${playlist.id}`" prefetch>
                    <span class="playlist__title text-outline">{{ playlist.name }}</span>
                </Link>

                <playlist-menu :playlist="playlist" class="playlist__menu" />

                <span v-if="playlist.description" class="playlist__description">{{ playlist.description }}</span>

                <!-- role="list" because the marker is styled away, and Safari/VoiceOver
                     drops list semantics from a list without markers. -->
                <ul class="playlist__facts" role="list">
                    <fact-pair :label="t('playlists.facts.tracks')" :value="String(playlist.tracks)" icon="song" />
                    <fact-pair
                        v-if="playtimeOf(playlist.duration)"
                        :label="t('playlists.facts.duration')"
                        :value="playtimeOf(playlist.duration)"
                        icon="duration"
                    />
                    <fact-pair
                        v-if="dateOf(playlist.createdAt)"
                        :label="t('playlists.facts.createdAt')"
                        :value="dateOf(playlist.createdAt)"
                        icon="recent"
                    />
                    <fact-pair
                        v-if="dateOf(playlist.updatedAt)"
                        :label="t('playlists.facts.updatedAt')"
                        :value="dateOf(playlist.updatedAt)"
                        icon="refresh"
                    />
                </ul>
            </li>
        </ul>

        <template v-else>
            <headline :size="3">{{ t("playlists.empty.headline") }}</headline>
            <p>{{ t("playlists.empty.text") }}</p>
        </template>
    </container>
</template>

<style scoped lang="scss">
@use "sass:list"; // https://sass-lang.com/documentation/modules/list
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/typography" as t;
@use "Abstracts/z-indexes" as z;

/* The ring gradient's angle, as a REGISTERED custom property — the registration is what
   makes the rotation possible at all: an unregistered `--angle` is an untyped token to
   the animation engine, so the gradient would jump between keyframes instead of sweeping.
   Typed as `<angle>`, it interpolates. Same mechanism as the hero's and
   `.glowing-border`'s, down to the motion guard below.

   The two at-rules below are NOT treated alike by Vue, which is worth knowing before
   reading a computed style: `@keyframes` IS rewritten by the scoped transform — the
   identifier comes out hashed (`playlist-border-rotate-3e76980c`) and every `animation-name`
   referencing it is rewritten to match — while `@property` is left global, since a custom
   property registration has no selector to scope. So the prefix is load-bearing on
   `--playlist-border-angle` (it shares one app-wide registry with the hero's and
   `.glowing-border`'s) and merely tidy on the keyframes name. A test asserting the exact
   animation name would be pinning a build hash; its own spec matches the prefix instead. */
@property --playlist-border-angle {
    syntax: "<angle>";
    inherits: false;
    initial-value: 135deg;
}

/* Exactly one turn, so the loop restarts on the frame it ended: any start angle works as
   long as the end is start + 360deg. */
@keyframes playlist-border-rotate {
    to {
        --playlist-border-angle: 495deg;
    }
}

.playlists__actions {
    /* Reads the BUTTON's own clearance token rather than a spacing rung: the neon halo and
       its reflection paint well outside the button's box and take part in no layout, so
       this is the button's metric, not a gap the page chose. */
    margin-block: 0 map.get(s.$c-button, "halo-clearance");
}

/* A column of panels. The UA marker and padding go (normalize.css leaves lists alone). */
.playlist__list {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-playlist, "list-gap");

    list-style: none;
}

/* ONE ENTRY — the hero's panel at list scale.

   Three columns: grip, the text column, the actions menu. Everything below the title
   starts in column 2, so the description and the facts line up under the TITLE rather
   than under the grip.

   COLUMNS ARE DECLARED, ROWS ARE NOT, and that is not laziness — `grid-template-areas`
   was the first shape here and it is wrong for this entry. Naming a `description` row
   creates that row whether or not anything lands in it, and a zero-height row still gets
   its `row-gap` on both sides: a playlist with no description grew a blank band under its
   title. With rows left implicit, the three first-row items are pinned and the rest
   auto-place, so a missing description means one row fewer rather than an empty one.

   `minmax(0, 1fr)` on the middle column, not `1fr`: a grid track's automatic minimum is
   its content's min-content size, so a single unbroken 40-character title (a German
   compound, a URL) would push the menu off the panel's edge instead of wrapping. The same
   trap the player bar's meta column and the queue row both document.

   `li.playlist`, WITH THE ELEMENT TYPE, and that is not style — a bare `.playlist` broke
   two icons on this very page. <Icon> puts the sprite symbol's NAME into its class list
   (`<svg class="icon large playlist">`), and the page's own headline and create button both
   ask for the `playlist` glyph, so a scoped `.playlist` rule matched those two <svg>s and
   handed them this panel's `display: grid`, white fill, 12px radius and 16px padding. They
   rendered as white rounded blobs where the glyph should be — a real regression that no
   test failed on, found by looking at a screenshot. The type selector cannot match an
   <svg>, which fixes it here; the underlying sharpness is that ANY class named after an
   icon will do this (`.icon.left` / `.icon.right` in DataTablePagination are the same
   coupling, used deliberately). */
li.playlist {
    display: grid;
    position: relative; // positioning context for the ring and the stretched link
    isolation: isolate; // keep both rungs contained to this panel

    grid-template-columns: auto minmax(0, 1fr) auto;

    padding: map.get(s.$c-playlist, "padding");
    gap: map.get(s.$c-playlist, "row-gap") map.get(s.$c-playlist, "column-gap");

    background-color: map.get(c.$c-playlist, "background");
    color: map.get(c.$c-playlist, "surface");
    border-radius: map.get(s.$c-playlist, "radius");

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color map.get(ti.$c-playlist, "hover"),
            box-shadow map.get(ti.$c-playlist, "hover");
    }

    /* THE ROTATING RING, the hero's technique unchanged: fill the ::before with the hue
       ramp over the border-box, then mask the padding-box back out so only the ring
       survives — a plain `border` can only take one flat colour.

       CONIC, not linear, and that is what makes the rotation even. A linear gradient's
       line is projected onto the box, so its length swings with the angle — on a panel
       this much wider than it is tall the bands visibly crawl near the horizontal and race
       near the vertical, even though the angle changes at a constant rate. A conic
       gradient sweeps around a centre point: equal angle, equal sweep, all the way round.

       The ramp's stops come from the token as a list; the first is repeated as the last so
       360deg meets 0deg on the same colour instead of a hard seam. */
    &::before {
        $ramp: map.get(c.$c-playlist, "border-ramp");

        position: absolute;
        inset: 0;
        z-index: z.$c-playlist;

        border: map.get(s.$c-playlist, "border") solid transparent;

        background: conic-gradient(from var(--playlist-border-angle), #{$ramp}, #{list.nth($ramp, 1)}) border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";

        pointer-events: none;

        /* Ambient, continuous motion, so it is opt-in behind `no-preference` like every
           other animation here — with the preference set (or unknown) every ring just
           holds the 135deg the property was registered with, and the panels differ in
           nothing, which is correct: the stagger exists to keep MOTION from synchronising.

           THE NEGATIVE DELAY IS THE STAGGER. A positive one would leave each ring frozen
           at its start angle for its own share of a minute before joining in; a negative
           one starts every ring immediately, already that far into the same loop. So the
           column is in motion from the first frame and no two neighbours are at the same
           angle. `--playlist-index` is published per row by the template; the `0` fallback
           means a row that somehow arrives without one still animates, in phase with the
           first. */
        @media (prefers-reduced-motion: no-preference) {
            animation: playlist-border-rotate map.get(ti.$c-playlist, "border-rotate") linear infinite;
            animation-delay: calc(var(--playlist-index, 0) * -1 * #{map.get(ti.$c-playlist, "border-stagger")});
        }
    }

    /* Under the pointer, or holding focus somewhere inside: the fill shifts a rung and a
       halo comes up in the app's control neon. Both, because either alone is too quiet —
       the DataTable's row-hover records the same finding — and neither can be a border
       colour here, that edge being already six colours and turning. The halo is the signal
       the hovered table row and the open popover give, so "this is live" reads the same
       across the app. */
    &:hover,
    &:focus-within {
        background-color: map.get(c.$c-playlist, "background-hover");
        box-shadow: 0 0 0.6em 0.1em map.get(c.$c-playlist, "hover-halo");

        /* AND THE TITLE'S FILL GOES WITH IT. Its letters are knocked out of the card — filled
           with the card's own colour — so a fill left on the resting pick would stop matching
           the moment the card lightened, and the outlined name would read as a filled one in
           very slightly the wrong shade. Both picks are opaque (see the token), so this
           follows exactly rather than approximately. */
        .playlist__title {
            color: map.get(c.$c-playlist, "background-hover");
        }
    }
}

/* THE ENTRY'S PARTS, in a block of their own — and the split is forced, not stylistic.
   Nesting `&__handle` inside `li.playlist` compiles to `li.playlist__handle`, which matches
   nothing: the grip is a <button>, the title a <span>, the facts a <ul>. So the panel's own
   declarations take the type-qualified selector above (they have to, see its note on the
   icon collision) and the parts take the plain BEM parent here, where no class can collide
   with a sprite name. Losing this split silently unstyles the whole entry — which is
   precisely what it did once. */
.playlist {
    /* THE ENTRY IN YOUR HAND — the clone that follows the pointer during a drag
       (`dragClass`). Sortable builds it with `cloneNode`, so it keeps this component's scope
       attribute and every class; what it does not keep is its place on the page, because it is
       appended to <body> to stay clear of clipping ancestors. The fill and ink it was
       inheriting therefore have to be restated, or the entry is drawn in whatever colours the
       page happens to use. The shadow is what says "lifted"; its offsets are em-based effect
       constants, as the ring's are.

       The ROTATING RING IS STILLED on the clone: `--playlist-index` is an inline custom
       property, so the clone inherits the phase but not the element's animation state, and a
       ring turning on a box that is already following the pointer is one motion too many. */
    &--dragging {
        background-color: map.get(c.$c-playlist, "background");
        color: map.get(c.$c-playlist, "surface");
        box-shadow: 0 0.25em 0.75em 0 map.get(c.$c-playlist, "drag-shadow");

        cursor: grabbing;

        &::before {
            animation: none;
        }
    }

    /* THE GAP IT LEFT — the real <li>, still in the list, which Sortable moves around to show
       where a drop would land (`ghostClass`). Faded rather than hidden: collapsing it would
       make the listing jump by a whole entry the moment a drag started, and the point of the
       gap is that it shows the destination. */
    &--ghost {
        opacity: 0.4;

        background-color: map.get(c.$c-playlist, "background-hover");
    }

    /* THE WHOLE PANEL IS THE LINK. An <a> cannot wrap this box — the grip and the menu are
       interactive, and an <a> may contain neither — so it stretches a positioned ::after
       over the panel instead. `inset: 0` resolves against `.playlist`, which is what its
       `position: relative` is for; the radius matches so the focus ring below traces the
       panel's rounded corners rather than a rectangle around them.

       Everything else in the entry stays UNDER this overlay on purpose, so aiming at the
       description or a fact tile opens the playlist. The two real controls are lifted back
       above it further down. */
    &__link {
        align-self: center;
        grid-column: 2;
        grid-row: 1;

        min-width: 0; // grid item: let a long unbroken title wrap rather than widen the track

        text-decoration: none;

        &::after {
            position: absolute;
            inset: 0;

            border-radius: map.get(s.$c-playlist, "radius");

            content: "";
        }

        /* The focus ring goes on the OVERLAY, not the anchor: the anchor is only as big as
           its title, so a ring on it would trace the words while the thing being activated
           is the whole panel. `:focus-visible` so a pointer click doesn't draw one. */
        &:focus-visible {
            outline: 0;

            &::after {
                outline: map.get(s.$c-playlist, "focus-outline") solid map.get(c.$c-playlist, "focus-outline");
                outline-offset: map.get(s.$c-playlist, "focus-outline");
            }
        }
    }

    /* …and the two real controls are lifted back above the overlay. A POSITION IS
       REQUIRED, not just a z-index: the overlay is positioned, so it paints above every
       non-positioned descendant of the same stacking context regardless of DOM order, and
       without this it would silently swallow both — the entry would navigate while nothing
       inside it could be pressed. Exactly the trap the play queue's row documents. */
    &__handle,
    &__menu {
        position: relative;
        z-index: z.$c-playlist;
    }

    /* Trailing edge of the title's row — never level with the facts, whose row it would
       otherwise be dragged into. See the grip below for why all three items in this row are
       CENTRED rather than top-aligned. */
    &__menu {
        align-self: center;
        grid-column: 3;
        grid-row: 1;
    }

    /* THE REORDER GRIP. Leading edge of the title's row. Everything below the title starts in
       column 2, so the text column is already clear of this one without the grip having to span
       anything.

       CENTRED, not top-aligned, and the numbers are why. This grip is the TALLEST thing in the
       row (a 19px glyph plus its padding, 35px) while the title's line box is 26.2px and the
       menu's button 32px — so `align-self: start` left the row 35px tall with the title reading
       4.4px high in it and the trigger 1.5px high, which is exactly the misalignment a phone
       shows most, since there the entry is a single line with nothing under it to distract from
       the offset. Centring all three puts every mid-point on the row's.

       The trade is a WRAPPED title, where the grip now sits against the middle of the block
       rather than its first line — the placement HeroSection argues against for its own heading.
       It is the right way round here: an entry's title is one line in the overwhelming majority
       of cases, and on the narrow viewports where it is not, the alternative misaligns every
       single-line entry to spare the rare long one.

       `grab` is the whole reason the cursor is declared here rather than inherited: the rest
       of the panel opens the playlist and says `pointer`, this strip moves it. The padding is
       what makes it findable — an icon with nothing beside it is a 16px target otherwise,
       which is the problem the play queue's grip solved by widening its own strip.

       Not `disabled` while reordering is unbuilt: a disabled button leaves the tab order and
       stops being announced, so the affordance would vanish entirely for the readers most
       likely to need telling it is there. It is a normal, focusable button that does nothing
       when pressed — the play queue's grip, again. */
    &__handle {
        display: grid;
        align-self: center;
        grid-column: 1;
        grid-row: 1;
        place-items: center;

        padding: map.get(s.$c-playlist, "handle-padding");
        border: 0;

        background-color: map.get(c.$c-playlist, "handle-background");
        color: map.get(c.$c-playlist, "handle");

        border-radius: map.get(s.$c-playlist, "handle-radius");

        cursor: grab;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                color map.get(ti.$c-playlist, "hover"),
                background-color map.get(ti.$c-playlist, "hover");
        }

        &:active {
            cursor: grabbing;
        }

        &:hover,
        &:focus-visible {
            background-color: map.get(c.$c-playlist, "handle-background-active");
            color: map.get(c.$c-playlist, "handle-active");
        }
    }

    /* The entry's heading, OUTLINED: filled with the card's own colour and drawn
       round with a line, so the name reads as cut out of the entry. How it is drawn — the line and
       its paint order, the glow, the weight, the leading — is the shared `.text-outline` class
       (styles/components/_text-outline.scss), which this element carries in the template and which
       a detail page's hero title wears too. Both wore the synthwave `.text-chrome` until that day;
       that went home to the app wordmark, now its only wearer.

       WHAT IS LEFT HERE IS THE FILL, the one thing the class cannot supply — and here it is TWO
       colours, which is why this consumer is the interesting one: `background` at rest and
       `background-hover` while the pointer is on the entry (the rule is up with the hover, where
       the card changes). An entry lightens a rung under the pointer, and letters left filled in the
       resting colour would stop matching and read as filled rather than knocked out. Both picks are
       opaque, so following is exact.

       Plus what belongs to the heading rather than to the treatment: how big it is, and in what
       face. */
    &__title {
        display: block;

        color: map.get(c.$c-playlist, "background");

        font-family: map.get(t.$c-playlist, "title");

        @include m.mqset(
            "font-size",
            #{map.get(s.$c-playlist, "title-font-size", "base")},
            #{map.get(s.$c-playlist, "title-font-size", "portrait")},
            #{map.get(s.$c-playlist, "title-font-size", "landscape")},
            #{map.get(s.$c-playlist, "title-font-size", "desktop")}
        );

        /* On the same clock as the card it is filled with, so the two move as one thing rather
           than the letters snapping while the panel eases. */
        @media (prefers-reduced-motion: no-preference) {
            transition: color map.get(ti.$c-playlist, "hover");
        }
    }

    /* Hidden on the narrowest screens, along with the facts below. At phone width the panel
       has room for a title and a grip, and stacking a blurb and four tiles under them turns a
       list you scan into a list you scroll — the name is what a reader picks a playlist by.
       Both come back at `portrait`. `display: none` rather than `visibility`, so the grid
       loses the row entirely instead of keeping an empty one (see the note on implicit rows). */
    &__description {
        display: none;
        grid-column: 2 / -1;

        @include m.mq("portrait") {
            display: block;
        }
    }

    /* A wrapping row of tiles, and — like the description above — GONE below `portrait`: at
       phone width the panel has room for a title and a grip, and four tiles under them turn a
       list you scan into a list you scroll.

       `align-content: start` keeps the lines packed at the top of the row rather than spread
       down it, and the UA marker and padding go. */
    &__facts {
        display: none;

        align-content: start;
        flex-wrap: wrap;
        grid-column: 2 / -1;

        padding: 0;
        margin: 0;
        gap: map.get(s.$c-playlist, "facts-gap");

        list-style: none;

        @include m.mq("portrait") {
            display: flex;
        }

        /* FROM `desktop` UP EACH PAIR READS ACROSS rather than stacking its label over its
           value. A tile is `flex-direction: column` by default, which is right in a facts card
           where several sit in a narrow panel — and wrong in an entry, where the row has width
           to spare and the stacked pairs make every entry two lines taller than it needs to be.
           Reaching for FactPair's own class from here is the same move the hero makes for the
           tile's halo; it works without `:deep()` because these tiles are rendered by this page
           (so their root carries its scope id) rather than slotted into it.

           `center`, NOT `baseline`, and this went the wrong way first. The argument for baseline
           was that the label is a step below body size and the value a step above, so a shared
           text baseline ought to read as one line. Measured, it does not: the label is itself a
           flex row (it carries the icon), and aligning the two boxes on their first baselines
           left the label's box 2.3px above the tile's middle while the value's sat exactly on
           it — the small caps visibly ride high. With `center` all three mid-points agree. */
        @include m.mq("desktop") {
            .fact-pair {
                align-items: center;
                flex-direction: row;

                gap: map.get(s.$c-facts, "label-icon-gap");
            }
        }

        /* `flex-grow: 0`, unlike the same tile inside a facts card where growing is what
           stops a line ending ragged: here a few chips sit against a wide panel, and
           stretching them across it would read as a table nobody asked for. The hero's
           metadata row makes the same call. */
        > * {
            flex-grow: 0;
        }
    }
}

</style>
