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
 * THE FAN OF SLEEVES IS NOT THIS COMPONENT'S ANY MORE. It was, until a playlist's hero
 * wanted the same object; it is now CoverSleeves, which owns how one, two or three
 * covers are laid out and why (its banner keeps that argument). What is left here is
 * the card around it.
 *
 * The covers arrive already picked and already shuffled — the server sends up to three
 * at random per request (see GenreController::fannedCovers), so the fan is different
 * on every visit by design. Nothing on this path re-orders them.
 *
 * The hover lift is a transition, and carries its reduced-motion guard.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverSleeves from "Components/Music/CoverSleeves.vue";
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
            <Link :href="artist.href" class="genre-artists__link" prefetch>
                <!-- The fan owns its own layout, its own degradation rule and its own
                     `aria-hidden` — it is decoration for the artist's name, which is the next
                     thing inside the same link. -->
                <cover-sleeves :covers="artist.covers" :title="artist.name" />
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
