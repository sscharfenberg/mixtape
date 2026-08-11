<script setup lang="ts">
/******************************************************************************
 * HeroSection
 * The first row of a detail page: a title and one line of metadata, with a square of
 * art on the trailing edge, in a panel framed by a slowly rotating gradient ring.
 * Generic by construction — a song fills it today, an album / artist / genre will fill
 * it with their own art and credits — so it takes everything through slots and knows
 * nothing about what it is describing.
 *
 * Six slots, all optional:
 *   #cover     the artwork — a <CoverImage size="xlarge">, which sizes and frames
 *              ITSELF to this square. Pass anything that is not an <img> (a bare
 *              <Icon>, or a CoverImage with no art, which renders its glyph) and the
 *              square is drawn as a dashed neon placeholder around it instead — see
 *              the `:has(img)` note in the styles for how that switch works without
 *              a prop. This slot deliberately does NOT size what it is handed: it
 *              used to, and that rule silently outranked CoverImage's own (a slotted
 *              component's ROOT element carries the slot scope id, so `:slotted(img)`
 *              reached inside it), leaving two places declaring one square with the
 *              hero quietly winning.
 *
 *              …unless `unframedCover` says the content brings its own size — see
 *              the prop.
 *   #title     the heading element. Its UA type is flattened so the hero's own
 *              headline face and size win, which lets the caller choose the level
 *              without anything here having to know.
 *
 *              IN THIS APP THAT LEVEL IS <h2>: the document's <h1> is the wordmark in
 *              AppHeader, which every page carries, so a hero that also claimed h1 gave
 *              each detail page two of them and no outline (fixed 2026-08-06 — the artist
 *              page is where the owner spotted it). The styling is level-agnostic
 *              (`> :slotted(*)`), so this is a document-outline decision only, with
 *              nothing visual riding on it.
 *
 *              ONLY THE PLAYLIST AND NOW PLAYING PAGES FILL IT as of 2026-08-11. The four
 *              Music detail pages moved their titles up into the same glowing <Headline>
 *              every listing wears, so arriving at an album looks like arriving anywhere
 *              else in the app — and their heroes now open with the facts. The slot stays
 *              because those two still read better with the title beside the art.
 *   #menu      what acts on the subject AS A WHOLE — the play / enqueue menu. Sits on the
 *              far end of the heading's line, level with its first line, and is a
 *              sibling of #title rather than part of it (the styles say why: a button
 *              inside the title inherits its transparent text fill and disappears).
 *
 *              LIKEWISE THE PLAYLIST PAGE'S ALONE now. The Music heroes swapped this
 *              popover for a visible row of buttons in #actions (SubjectActions) on the
 *              same day: a page's two most likely actions should not be behind a "…". A
 *              playlist keeps the menu because its hero already carries a row of its own.
 *   #description  the subject's own words about itself, between the title and the facts —
 *              a playlist's blurb today. Prose rather than a tile, which is why it is not
 *              part of #metadata: that slot is a <ul> of chips, and a sentence in one
 *              would be a chip the width of the panel.
 *   #metadata  the labelled values under the title — artist / album / year for a song.
 *              Rendered as a wrapping row of list items, so pass `FactPair`s (the same
 *              tile the facts cards are built from) and they will line up here; the row
 *              only decides the flow and the gap.
 *   #actions   what the reader can DO with the subject — enqueue it, and in time play
 *              it and share it. Sits last, under the facts, because the controls act on
 *              the thing those facts have just identified. A wrapping row like
 *              #metadata, and equally layout-only: pass `Button`s and they keep their
 *              own look.
 *
 * Every wrapper is rendered only when its slot was actually passed, so a consumer with
 * no art — or with nothing to say under its title — gets no empty box holding a padding,
 * a gap or a halo open. One consequence worth knowing: the dashed cover placeholder shows
 * when the slot EXISTS but holds something other than an image, and not at all when the
 * slot was left out, which is the difference between "no artwork on file" and "this kind
 * of page has no artwork".
 *
 * It is a panel of its own rather than a Card: same fill, but framed by the rotating
 * ring instead of a flat border, which is what makes the top of a detail page read as
 * its loudest element without a second card style.
 *****************************************************************************/
defineProps<{
    /**
     * The cover slot holds something that brings its OWN size — so place it, do not frame it.
     *
     * Off by default, which is the case every Music detail page wants: a `CoverImage
     * size="xlarge"` has no width of its own and fills whatever it is given, so the hero has
     * to declare the square (220 / 200 / 240px per breakpoint) or there is nothing to fill.
     * The dashed placeholder depends on that square too — an album with no artwork renders a
     * bare glyph, and the frame is the only thing left saying how big the missing picture was.
     *
     * On, the box shrinks to its content and draws no frame at all. The playlist page needs it
     * because a fan of sleeves (CoverSleeves) is a FIXED size, so the square reserved 240px of
     * height for a 96px object and the hero opened with a band of empty panel. It also draws
     * its own placeholder when there is nothing to fan, so a dashed frame around it would be
     * the second thing saying the same.
     */
    unframedCover?: boolean;
}>();
</script>

<template>
    <div class="hero-section">
        <div
            v-if="$slots.cover"
            :class="['hero-section__cover', { 'hero-section__cover--unframed': unframedCover }]"
        >
            <slot name="cover" />
        </div>

        <div
            v-if="$slots.title || $slots.description || $slots.metadata || $slots.actions"
            class="hero-section__meta"
        >
            <!-- The heading row: the title, and whatever acts on the subject as a whole pinned
                 to the far end of it. The menu is a SIBLING of the title rather than something
                 passed into it, and that is not tidiness — `__title` fills its letters with the
                 panel's own colour and defines them with a stroke, and BOTH of those inherit:
                 a button slotted inside would come out invisible, wearing an outline. (It was
                 true for the same reason when the title was clipped chrome; only the property
                 that would do the damage has changed.) -->
            <div v-if="$slots.title || $slots.menu" class="hero-section__heading">
                <div v-if="$slots.title" class="hero-section__title text-outline"><slot name="title" /></div>
                <div v-if="$slots.menu" class="hero-section__menu"><slot name="menu" /></div>
            </div>
            <!-- The subject in its own words, under the title and above the facts: it says
                 what the thing IS, which a reader wants before the numbers describing it. -->
            <p v-if="$slots.description" class="hero-section__description"><slot name="description" /></p>
            <!-- role="list" because the marker is styled away, and Safari/VoiceOver drop
                 list semantics from an unmarked list. -->
            <ul v-if="$slots.metadata" class="hero-section__metadata" role="list">
                <slot name="metadata" />
            </ul>
            <!-- Last, under the facts: the controls act on the thing the facts have just
                 finished identifying, and a reader meets them in that order. -->
            <div v-if="$slots.actions" class="hero-section__actions"><slot name="actions" /></div>
        </div>
    </div>
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

/* The border gradient's angle, as a REGISTERED custom property — the registration is
   what makes the rotation possible at all: an unregistered `--angle` is an untyped
   token to the animation engine, so the gradient would jump between keyframes instead
   of sweeping. Typed as `<angle>`, it interpolates. Same mechanism as
   `.glowing-border`'s `--glowing-deg` (styles/components/_glowing-border.scss), down to
   the motion guard below.

   `@property` is a global at-rule — Vue's scoped transform only rewrites selectors —
   so the name is component-prefixed to keep the app-wide registry collision-free. */
@property --hero-section-border-angle {
    syntax: "<angle>";
    inherits: false;
    initial-value: 135deg;
}

/* Exactly one turn, so the loop restarts on the frame it ended: any start angle works
   as long as the end is start + 360deg. */
@keyframes hero-section-border-rotate {
    to {
        --hero-section-border-angle: 495deg;
    }
}

/* Stacked on a phone (cover, then title — the reading order), side-by-side from
   `portrait` up, where a title plus a square cover fit across without either being
   squeezed. The art sits on the TRAILING edge there, so the text starts at the panel's
   leading edge where a reader's eye already is.

   Both halves take the row's full height (the grid default) rather than being centred in
   it, so the title starts at the top of the panel — the metadata line then reads as
   hanging off the title rather than as floating in the middle of the row. */
.hero-section {
    display: grid;
    position: relative; // positioning context for the border ring below
    isolation: isolate; // keep the ring's rung contained to this panel

    padding: map.get(s.$c-hero-section, "padding");
    gap: map.get(s.$c-hero-section, "gap");

    background-color: map.get(c.$c-hero-section, "background");
    border-radius: map.get(s.$c-hero-section, "radius");

    /* TWO COLUMNS ONLY WHEN THERE IS A COVER TO PUT IN THE SECOND. Declared unconditionally,
       the template still creates that track for a hero that slots nothing into it: the track
       resolves to zero width, but the `gap` between the two columns does NOT go away — so
       every coverless hero carried a stripe of dead space inside its trailing padding, which
       reads as a stray margin nobody wrote.

       `:has()` rather than a prop, keyed off the DOM exactly like the cover slot's own
       `:has(img)` test below: the caller decides by what it slots in, and cannot get the two
       out of step by passing a cover and forgetting a flag. */
    @include m.mq("portrait") {
        &:has(> .hero-section__cover) {
            grid-template-columns: 1fr auto;
        }
    }

    /* The featured border, drawn as a gradient ring: fill the ::before with the hue ramp
       over the border-box, then mask the padding-box back out so only the ring survives —
       a plain `border` can only take one flat colour. Same technique as the shared
       `.frosted-glass`, but owned here: this frame is the hero's own decision, and it sits
       over the panel's opaque fill rather than glass.

       CONIC, not linear, and that is what makes the rotation even. A linear gradient's
       line is projected onto the box, so its length is |W·sin(a)| + |H·cos(a)| — on a
       panel this much wider than it is tall, that swings by a factor of five as the angle
       turns, and the bands visibly crawl near the horizontal and race near the vertical
       even though the angle itself changes at a constant rate. A conic gradient sweeps
       around a centre point, so equal angle equals equal sweep the whole way round.
       `.glowing-border` uses one for the same reason.

       The ramp's stops come from the token as a list; the first is repeated as the last so
       360deg meets 0deg on the same colour instead of a hard seam. `border-radius:
       inherit` makes the ring follow the panel's corners, and it paints on the "raised"
       rung so it reads unbroken even where the cover square meets it. */
    &::before {
        $ramp: map.get(c.$c-hero-section, "border-ramp");

        position: absolute;
        inset: 0;
        z-index: z.$c-hero-section;

        border: map.get(s.$c-hero-section, "border") solid transparent;

        background: conic-gradient(from var(--hero-section-border-angle), #{$ramp}, #{list.nth($ramp, 1)}) border-box;

        border-radius: inherit;
        mask:
            linear-gradient(black, black) border-box,
            linear-gradient(black, black) padding-box;
        mask-composite: subtract;

        content: "";

        pointer-events: none;

        /* Ambient, continuous motion, so it is opt-in behind `no-preference` like every
           other animation here — with the preference set (or unknown) the ring just holds
           the 135deg the property was registered with. */
        @media (prefers-reduced-motion: no-preference) {
            animation: hero-section-border-rotate ti.$c-hero-section linear infinite;
        }
    }

    /* The frame around the cover slot: a fixed square that grows a step at the wider
    breakpoints, capped at 100% so the stacked phone layout can't push it past the
    panel's padding. `place-items: center` is for whatever a caller slots in that ISN'T
    an image — an icon standing in for missing art.

    `align-self: start` keeps the square SQUARE. Without it the frame takes the grid's
    default stretch, which hands it a definite height — and a definite height beats
    `aspect-ratio`, so a title long enough to make the text column the taller of the two
    (four wrapped lines happens in a ripped collection) would pull the art into a
    rectangle. Column 2 because the art sits on the trailing edge from `portrait` up;
    below that the grid is one column and the DOM order (cover first) stands. */
    &__cover {
        display: grid;
        align-self: start;
        place-items: center;

        overflow: hidden; // clip a non-square scan to the frame's corners

        width: map.get(s.$c-hero-section, "cover", "base");
        max-width: 100%;
        aspect-ratio: 1;

        border-radius: map.get(s.$c-hero-section, "cover-radius");

        @include m.mq("portrait") {
            grid-column: 2;
            grid-row: 1;
        }

        @include m.mq("landscape") {
            width: map.get(s.$c-hero-section, "cover", "landscape");
        }

        @include m.mq("desktop") {
            width: map.get(s.$c-hero-section, "cover", "desktop");
        }

        /* Nothing sizes the slotted artwork here, on purpose. CoverImage declares its own
           square, frame and rounding per size (s.$c-cover-image), and a rule in this block
           would OVERRIDE it rather than back it up: Vue puts the slot scope id on a slotted
           component's ROOT element, and CoverImage's root is the <img> itself — so the
           obvious `> :slotted(img) { width: 100% }` matched straight through the component
           and won on specificity, leaving one square declared in two places with this file
           quietly deciding it. The frame below still carries its own width for the case that
           needs it: when there is no art to fill it. */

        /* No art on disk: the same square, drawn as a dashed neon outline around whatever the
           caller put there instead (a muted icon), so the hero keeps its shape and the gap
           reads as "nothing here" rather than as a failed image.

           Keyed off `:has(img)` rather than a prop, because the DOM already answers the
           question — the caller decides by what it slots in, and cannot get the two out of
           sync by passing an image and forgetting the flag. */
        &:not(:has(img)) {
            border: map.get(s.$c-hero-section, "cover-placeholder-border") dashed
                map.get(c.$c-hero-section, "cover-placeholder-border");

            background-color: map.get(c.$c-hero-section, "cover-placeholder-background");
            color: map.get(c.$c-hero-section, "cover-placeholder-icon");
        }

        /* THE CONTENT BRINGS ITS OWN SIZE — see the `unframedCover` prop. Every declaration
           here undoes one the square made, and each has to be undone explicitly rather than
           the square being made conditional: the width is a per-breakpoint set (three more
           media queries to mirror), and `aspect-ratio` and the dashed frame are separate
           decisions that happen to hang off the same case.

           `width: auto` and `aspect-ratio: auto` are what actually remove the whitespace: a
           fan of sleeves is 152×96, and in a 240px square it sat centred with a band of empty
           panel above and below it. The frame goes with them, because whatever is slotted in
           is now responsible for saying it has nothing to show — CoverSleeves draws its own
           placeholder sleeve, and a dashed box around that says it twice.

           `overflow: visible` because the fan's outer sleeves are ROTATED, so they reach
           beyond their own box; clipped to a box that now hugs them, their corners would be
           cut off. The square could afford to clip, being far bigger than what it held. */
        &--unframed {
            overflow: visible;

            width: auto;
            aspect-ratio: auto;

            @include m.mq("landscape") {
                width: auto;
            }

            @include m.mq("desktop") {
                width: auto;
            }

            &:not(:has(img)) {
                border: 0;

                background-color: transparent;
            }
        }
    }

    /* The subject's own words. Sized down a step from the body copy and one rung quieter in
       ink, so it reads as a caption to the title rather than competing with the facts under
       it — and `margin: 0` because it is a <p> inside a flex column that already spaces its
       children with a gap. */
    &__description {
        margin: 0;

        color: map.get(c.$c-hero-section, "description");

        font-size: map.get(s.$c-hero-section, "description-font-size");
    }

    /* Column 1 — the text leads, the art follows. Explicit rather than left to
       auto-placement, so the pair can't drift out of the row if a third block is ever
       slotted in beside them. */

    /* What acts on the subject — play, enqueue, share. Wraps, because at hero width a
       row of buttons is the first thing to run out of room. */

    &__actions {
        display: flex;
        flex-wrap: wrap;

        gap: map.get(s.$c-hero-section, "metadata-gap");
    }

    &__meta {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$c-hero-section, "meta-gap");

        @include m.mq("portrait") {
            grid-column: 1;
            grid-row: 1;
        }
    }

    /* The heading and whatever acts on the whole subject, on one line with the menu pushed to
       the far end. `align-items: start` rather than centre, because the title wraps to two or
       three lines on a phone and a trigger floating at the middle of that block reads as
       unattached to it; level with the first line, it reads as belonging to the title.

       The title keeps `min-width: 0` so a long unbroken word still shrinks and wraps instead of
       pushing the trigger off the edge — the same flex trap the player bar's meta column and the
       queue row both document. */
    &__heading {
        display: flex;
        align-items: start;
        justify-content: space-between;

        gap: map.get(s.$c-hero-section, "meta-gap");
    }

    &__menu {
        flex: 0 0 auto;
    }

    /* The page's heading, at whatever level the caller passed (an <h2> here — see the banner).
       The caller picks the ELEMENT (which level belongs in the document outline) and the hero
       decides how it looks.

       OUTLINED LETTERING as of 2026-08-10, where this wore the synthwave `.text-chrome` ramp:
       the glyphs are filled with the PANEL'S OWN colour and drawn round with a line, so they read
       as cut out of the hero rather than printed on it, with one soft glow behind them. How that
       is drawn — the line and its paint order, the glow, the weight, the leading — is the shared
       `.text-outline` class (styles/components/_text-outline.scss), which this element carries in
       the template alongside its own; a playlist entry's title wears the same one. The chrome went
       home to the app wordmark, now its only wearer (AppHeaderTitle owns those styles outright).

       WHAT IS LEFT HERE IS THE FILL, which is the one thing that class cannot supply: the effect is
       letters knocked out of THIS panel, so the colour has to be the panel's own token. Reading
       `background` rather than re-picking `white` / `black` is what keeps "the same colour as the
       panel" true when the panel is retuned — a second pick would be one edit away from a title
       that no longer knocks out.

       Plus what belongs to the heading rather than to the treatment: how big it is, and in what
       face. */
    &__title {
        min-width: 0; // flex item since the heading row arrived; see `__heading`

        color: map.get(c.$c-hero-section, "background");

        font-family: map.get(t.$c-hero-section, "title");

        @include m.mqset(
            "font-size",
            #{map.get(s.$c-hero-section, "title-font-size", "base")},
            #{map.get(s.$c-hero-section, "title-font-size", "portrait")},
            #{map.get(s.$c-hero-section, "title-font-size", "landscape")},
            #{map.get(s.$c-hero-section, "title-font-size", "desktop")}
        );

        > :slotted(*) {
            margin: 0;

            font-size: inherit;
            line-height: inherit;
        }
    }


    /* A wrapping row of tiles rather than one line of prose. The UA list marker and padding
       go (normalize.css leaves lists alone). */
    &__metadata {
        display: flex;
        flex-wrap: wrap;

        padding: 0;
        margin: 0;
        gap: map.get(s.$c-hero-section, "metadata-gap");

        list-style: none;

        /* `flex-grow: 0`, unlike the same tile inside a facts card, where growing is what
           stops a line ending ragged: here a few chips sit against a wide panel, and
           stretching them across it would read as a table nobody asked for.

           The halo is the hero's own addition to a tile that is flat everywhere else — see
           c.$c-hero-section "metadata-halo" for why it belongs here and not in the card. */
        > :slotted(*) {
            flex-grow: 0;

            box-shadow: 0 0 map.get(s.$c-hero-section, "metadata-halo") map.get(c.$c-hero-section, "metadata-halo");
        }

        /* A tile that LINKS gets the same halo in a different colour, because the neon one
           vanishes on it: in light mode that glow is blue drawn around a pale blue chip, and
           in dark mode it is a low-alpha blue with a dark tile behind it. Its own ink is the
           one colour guaranteed to read in both — see c.$c-hero-section
           "metadata-halo-link".

           Naming FactPair's class from here is deliberate, and the narrowest way to say it:
           the halo is the HERO's decision (the tile is flat in a card), so the exception has
           to live where the rule does. Same shape as the `:has(img)` test in the cover slot
           above — this component keys off what it was actually handed.

           Note this reaches INTO a slotted component on purpose, by naming its own class,
           and only to paint. That is the safe version of what the cover slot got wrong: a
           `:slotted` rule that sets SIZE lands on the component's root and outranks the
           sizing that component declares for itself. */
        > :slotted(.fact-pair--link) {
            box-shadow: 0 0 map.get(s.$c-hero-section, "metadata-halo") map.get(c.$c-hero-section, "metadata-halo-link");
        }
    }
}
</style>
