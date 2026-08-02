<script setup lang="ts">
/******************************************************************************
 * GenreArtists
 * The genre page's ARTISTS tab: one card per artist whose MAIN genre this is, each
 * leading with a fanned stack of that artist's covers and carrying the three numbers
 * that describe them WITHIN this genre.
 *
 * Page-local rather than a shared Music component, unlike Discography: it is built
 * around "in this genre" — which is not a question an artist page or an album page
 * can ask. If a second caller ever needs it, that is the moment to promote it.
 *
 * THE FAN DEGRADES HONESTLY, which is the load-bearing decision here rather than a
 * fallback. In this collection half of all artists have exactly one album and only a
 * third have three or more, so the three-cover fan is the MINORITY card and the
 * one-cover card is the common one. Padding a stack out to three by repeating a
 * sleeve, or by filling with placeholders, would make the most frequent card on the
 * page look like a rendering fault. So: three or more covers fan three, two fan two,
 * one sits straight, and an artist whose albums carry no artwork at all gets a single
 * placeholder.
 *
 * The covers arrive already picked and already shuffled — the server sends up to three
 * at random per request (see GenreController::fannedCovers), so the fan is different
 * on every visit by design. Nothing here re-orders them.
 *
 * Rotation is a STATIC transform, not a transition, so it needs no reduced-motion
 * guard; the hover lift does, and has one.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import { formatClock, formatDecimals } from "Utils/formatting";

/** One artist of this genre, as GenreController shaped them — every value raw. */
export interface GenreArtist {
    id: string;
    name: string;
    /** How many of their songs carry this genre's tag. */
    songs: number;
    /** How many of their albums have this genre as their dominant one. */
    albums: number;
    /** Total playing time of those songs, in seconds. 0 rather than null — the server COALESCEs. */
    duration: number;
    /**
     * Up to three cover URLs, already chosen at random from the artist's albums in this
     * genre. Empty when none of those albums carries artwork, which the card renders as a
     * single placeholder rather than an empty space.
     */
    covers: string[];
    /** The artist's own page — the whole card is a link to it. */
    href: string;
}

const props = defineProps<{
    /**
     * The artists to show, already ordered by the server: most songs in this genre first,
     * then alphabetically. Rendered in the order given — the ordering is a server decision,
     * so re-sorting here would only let the two disagree.
     */
    artists: GenreArtist[];
}>();

const { t, locale } = useI18n();

/**
 * The sleeves to draw, in DOM order, each with the class that places it.
 *
 * Built as an explicit list rather than left to `v-for` over `covers` with index maths in
 * the template, because the middle sleeve must come LAST in the DOM: the three overlap,
 * and the one on top is the one painted last. Doing it with z-index instead would work
 * until the cards sit inside the tab panel's stacking context, which is exactly the sort
 * of thing that breaks silently much later.
 */
const sleeves = (artist: GenreArtist): { src: string | null; position: string }[] => {
    const covers = artist.covers.slice(0, 3);

    if (covers.length === 0) return [{ src: null, position: "single" }];
    if (covers.length === 1) return [{ src: covers[0], position: "single" }];
    if (covers.length === 2) {
        return [
            { src: covers[0], position: "left" },
            { src: covers[1], position: "right" }
        ];
    }

    return [
        { src: covers[0], position: "left" },
        { src: covers[2], position: "right" },
        { src: covers[1], position: "middle" }
    ];
};

/** The artist's song count, grouped for the active locale, already pluralised. */
const songCount = (artist: GenreArtist): string => t("music.genre.artists.songCount", artist.songs);

/** Their album count in this genre, already pluralised. */
const albumCount = (artist: GenreArtist): string => t("music.genre.artists.albumCount", artist.albums);

/**
 * How long those songs run, as a clock. Never null — the server COALESCEs the sum — so
 * the chip always renders; `?? ""` stands in for a condition that cannot be false.
 */
const playingTime = (artist: GenreArtist): string => formatClock(artist.duration) ?? "";

/** Grouped song count for the tab's own empty/summary text. */
const total = computed(() => formatDecimals(props.artists.length, locale.value));
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, N items" before the cards, the
         one thing a bare grid of links would say worse. -->
    <ul v-if="artists.length > 0" class="genre-artists" :aria-label="t('music.genre.artists.label', { total })">
        <li v-for="artist in artists" :key="artist.id" class="genre-artists__item">
            <Link :href="artist.href" class="genre-artists__link">
                <!-- `aria-hidden`: the fan is decoration for the artist's name, which is the
                     next thing inside the same link. Naming each sleeve would have a screen
                     reader read three album titles before reaching the person. -->
                <span class="genre-artists__fan" aria-hidden="true">
                    <span
                        v-for="(sleeve, index) in sleeves(artist)"
                        :key="index"
                        :class="['genre-artists__sleeve', `genre-artists__sleeve--${sleeve.position}`]"
                    >
                        <!-- `large` is the 96px step, which is exactly what a sleeve is here.
                             NOT `xlarge`: that one fills its container but carries the HERO
                             frame with it — the thick `featured` border and rounding meant
                             for a 240px sleeve — which at this size reads as a picture frame
                             around the art rather than the edge of a record. -->
                        <cover-image :src="sleeve.src" :title="artist.name" size="large" decorative />
                    </span>
                </span>
                <span class="genre-artists__name">{{ artist.name }}</span>
                <!-- One chip per number, like the Discography's facts — all three always
                     render, because a count of zero is a fact about the artist rather than a
                     missing tag. -->
                <span class="genre-artists__meta">
                    <span class="genre-artists__fact">{{ songCount(artist) }}</span>
                    <span class="genre-artists__fact">{{ albumCount(artist) }}</span>
                    <span class="genre-artists__fact">{{ playingTime(artist) }}</span>
                </span>
            </Link>
        </li>
    </ul>
    <p v-else>{{ t("music.genre.noArtists") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* A grid at every width. Unlike the Discography there is no row layout to fall back to:
   a card here is mostly its fan, and a fan laid out as a row would be a wide picture with
   three words beside it — which is neither a stack of records nor a legible list. The
   column floor is what adapts instead, collapsing to one column on a phone. */
.genre-artists {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(map.get(s.$c-genre-artists, "card-min"), 100%), 1fr));

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-genre-artists, "gap");

    list-style: none;

    &__item {
        display: flex;
    }

    &__link {
        display: flex;
        align-items: center;
        flex-grow: 1;
        flex-direction: column;

        padding: map.get(s.$c-genre-artists, "card-padding");
        border: map.get(s.$c-genre-artists, "border") solid map.get(c.$c-genre-artists, "border");
        gap: map.get(s.$c-genre-artists, "stack-gap");

        background-color: map.get(c.$c-genre-artists, "background");
        color: inherit;
        border-radius: map.get(s.$c-genre-artists, "radius");

        text-align: center;
        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-discography ease-out,
                box-shadow ti.$c-discography ease-out;
        }

        /* The house treatment, identical to the Discography tile one tab away and to the
           DataTable's clickable rows: the two-layer control-neon halo over a wash that only
           SHIFTS the card's existing fill. Both layers are soft and em-based — this was
           first written as a hard `0 0 0 1px` ring plus a tight blur, which reads as an
           outline drawn around the card rather than as the card lighting up.

           `position: relative` so the glow paints above the neighbouring cards rather than
           under them. */
        &:hover {
            position: relative;

            background-color: map.get(c.$c-genre-artists, "hover-background");
            box-shadow:
                0 0 0.6em 0.1em map.get(c.$c-genre-artists, "glow"),
                0 0 1.5em 0.25em map.get(c.$c-genre-artists, "glow");
        }

        /* The card is already the target, so it needs no underline — but it does need a
           visible keyboard focus ring, since nothing else here says which card is focused.
           Same as the Discography tile's. */
        &:focus-visible {
            outline: 2px solid currentcolor;
        }
    }

    /* The fan's box. `position: relative` so the sleeves can stack on top of one another,
       and a FIXED height because they are absolutely positioned and would otherwise
       contribute nothing to the card's height — every card would collapse to its text. */
    &__fan {
        display: flex;
        position: relative;
        align-items: center;
        justify-content: center;

        width: 100%;
        max-width: map.get(s.$c-genre-artists, "fan-box");
        height: map.get(s.$c-genre-artists, "fan-size");
    }

    /* Sized by its content — the CoverImage inside is already exactly one sleeve wide — so
       there is no second copy of that measurement here to drift from it. `line-height: 0`
       because the image is inline content and would otherwise sit on a text baseline,
       leaving a sliver of box beneath it for the shadow to trace. */
    &__sleeve {
        position: absolute;

        overflow: hidden;

        border-radius: map.get(s.$c-genre-artists, "fan-radius");

        /* Each sleeve carries the shadow that lifts it off the one beneath; the fan is built
           from overlap, so this is what keeps the stack legible as separate records rather
           than one flat shape. CoverImage draws the hairline edge itself. */
        box-shadow: 0 2px 6px -1px map.get(c.$c-genre-artists, "fan-shadow");

        line-height: 0;

        /* Deliberately OUTSIDE the reduced-motion guard: these are static transforms, not
           motion. The rule covers transitions and running animations; a sleeve that is
           simply drawn at an angle animates nothing. */
        &--left {
            transform: translateX(calc(-1 * #{map.get(s.$c-genre-artists, "fan-offset")}))
                rotate(calc(-1 * #{map.get(s.$c-genre-artists, "fan-angle")}));
        }

        &--right {
            transform: translateX(map.get(s.$c-genre-artists, "fan-offset"))
                rotate(map.get(s.$c-genre-artists, "fan-angle"));
        }

        /* Straight on, and painted last — it is the one on top. */
        &--middle,
        &--single {
            transform: none;
        }
    }

    /* WRAPS, and shows the whole name however long it is. A credit here is regularly a
       collaboration rather than a band — this collection's longest is 106 characters, four
       performers and their instruments — and truncating that leaves every one of them
       reading the same for the first thirty characters.

       It was written as an ellipsis first, which silently did nothing: a card is a GRID
       item, and a grid item's `min-width` defaults to `auto`, so a long unbreakable name
       grows the whole column instead of overflowing the box `text-overflow` needs.
       `overflow-wrap: anywhere` is what makes it breakable, which both lets the text wrap
       and stops one long name widening the card it sits in. */
    &__name {
        overflow-wrap: anywhere;
        max-width: 100%;

        font-weight: bold;
    }

    /* `margin-top: auto` pins the numbers to the bottom of the card. Now that a name can be
       one line or four, cards in the same grid row are the height of the tallest — without
       this the chips would sit wherever each name happened to end, and a row of cards would
       have its numbers at four different heights. */
    &__meta {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;

        margin-top: auto;
        gap: map.get(s.$c-genre-artists, "meta-gap");

        color: map.get(c.$c-genre-artists, "surface-meta");
    }

    &__fact {
        padding: map.get(s.$c-genre-artists, "meta-padding");

        background-color: map.get(c.$c-genre-artists, "meta-background");
        border-radius: map.get(s.$c-genre-artists, "meta-radius");

        white-space: nowrap;
    }
}
</style>
