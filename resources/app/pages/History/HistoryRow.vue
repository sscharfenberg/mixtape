<script setup lang="ts">
/******************************************************************************
 * HistoryRow
 * One listen: what was played, what it belongs to, and when it was played. A row in the list
 * a day of the history opens onto.
 *
 * PAGE-LOCAL rather than shared, like PlaylistTracks' rows and unlike Discography: a row here
 * is built around a PLAY — an event with a timestamp — which is not a thing any other listing
 * in the app has. It borrows the shape of a playlist row (a pip run beside a name, the whole
 * row a link) rather than the component, because what the two show has almost nothing in
 * common past the title.
 *
 * IT IS A LINK, NOT A PLAYER. Every other listing of tracks in this app can start audio; this
 * one deliberately cannot. A history is a record of what happened, and a play button on it
 * would make the page that shows your listening a page that adds to it — so the row leads to
 * the thing itself, where the ordinary controls are.
 *
 * THE WHOLE ROW IS THE TARGET, which is why the markup looks the way it does: the name's
 * anchor stretches a `::after` over the row rather than wrapping it, so aiming at a pip or at
 * the empty space between them opens the same page. Same solution, same reason, as
 * PlaylistTracks and the playlists listing — and here the row holds no controls at all, so
 * nothing has to be lifted back above the overlay.
 *
 * WHAT KIND OF THING IT WAS comes first, as a pip. A song and an audiobook chapter are told
 * apart by nothing else in the row — both have a title, a credit and a container — and the
 * two read completely differently once you know which is which ("Kapitel 12" under a book is
 * not a song with a strange name). The glyph does the work at a glance and the word carries it
 * for a screen reader.
 *
 * THE CREDIT IS ONE PIP FOR TWO FACTS. A song's is its artist, a chapter's is its AUTHOR (an
 * audiobook's author hangs off the chapter — docs/audiobooks.md), and the server sends
 * whichever applies under one key with `kind` beside it. The glyph follows the kind, so the
 * pip says which of the two it is holding without a word for it.
 *
 * A pip whose fact is missing is DROPPED rather than drawn empty: a file crediting nobody, a
 * loose track under no album. That is the same rule the playlist row follows, and the reason
 * a row can be as short as a title and a clock.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { formatDateTime, formatTimeOfDay } from "Utils/formatting";

/** One listen, as HistoryController shaped it. */
export interface HistoryPlay {
    /** The play row's own id — the key this list is rendered by, since a track can repeat. */
    id: string;
    /** ISO-8601 instant of the listen. Raw: the row prints it in the reader's own zone. */
    playedAt: string;
    /** Which kind of thing was listened to — picks this row's glyphs and its word. */
    kind: "music" | "audiobook";
    /** The track's own title, as tagged. */
    name: string;
    /** Artist for a song, author for a chapter, or null when the file credits nobody. */
    creator: string | null;
    /** Album for a song, book for a chapter, or null for a loose file. */
    container: string | null;
    /** Where the row leads: the song's own page, or a chapter's BOOK. */
    href: string;
}

const props = defineProps<{
    /** The listen this row draws. */
    play: HistoryPlay;
}>();

const { t, locale } = useI18n();

/**
 * Whether this row is an audiobook chapter — asked once, since three things follow from it.
 *
 * A computed rather than three comparisons in the template: the row's glyphs and its word all
 * turn on the same question, and spelling it out at each of them is how one of them ends up
 * saying "song" beside a book.
 */
const isChapter = computed<boolean>(() => props.play.kind === "audiobook");

/**
 * The clock this listen happened at, in the reader's own timezone.
 *
 * THE TIME ALONE, not the date: this row lives inside a section whose heading is the day, so a
 * date here would repeat that heading on every row of it. What tells one listen from the next
 * within a day is the minutes.
 */
const time = computed<string | null>(() => formatTimeOfDay(props.play.playedAt, locale.value));

/**
 * The whole instant, for the pip's accessible name.
 *
 * The clock on screen is unambiguous only because of where it sits — under a heading, in a list
 * of one day. Read on its own by a screen reader, "21:34" is a time with no day attached, so
 * the name it announces carries both. There is deliberately no tooltip alongside it; the
 * template says why one could not have worked.
 */
const instant = computed<string | null>(() => formatDateTime(props.play.playedAt, locale.value));
</script>

<template>
    <li class="history-row">
        <span class="history-row__subject">
            <!-- WHAT KIND OF THING WAS PLAYED. The app's usual pip, wearing the glyph that
                 names this kind everywhere else, so the two are told apart before the words
                 are read at all. -->
            <span class="history-row__kind">
                <icon :name="isChapter ? 'audiobook' : 'song'" :size="1" />
                {{ t(`history.kind.${props.play.kind}`) }}
            </span>

            <!-- The row's NAVIGATION, and the whole row is its target: the anchor stretches a
                 `::after` over the row rather than wrapping it. `prefetch` warms the page on
                 hover, as every other listing does — and safely, because what is at the other
                 end is a page one only reads (CLAUDE.md → the prefetch rule). -->
            <Link :href="props.play.href" class="history-row__name" prefetch>{{ props.play.name }}</Link>
        </span>

        <!-- The two facts that place the track. Each is dropped rather than drawn empty when
             the tags do not carry it, and each wears the glyph its KIND wants: an artist or an
             author, an album or a book. -->
        <span class="history-row__meta">
            <span v-if="props.play.creator" class="history-row__fact">
                <icon :name="isChapter ? 'author' : 'artist'" :size="1" />{{ props.play.creator }}
            </span>
            <span v-if="props.play.container" class="history-row__fact">
                <icon :name="isChapter ? 'audiobook' : 'album'" :size="1" />{{ props.play.container }}
            </span>
        </span>

        <!-- WHEN, at the trailing edge — the one fact about the LISTEN rather than about the
             music, which is why it sits apart from the pips that describe the track.
             NO TOOLTIP, deliberately. One was the obvious way to offer the whole instant and it
             could never have opened: the name's stretched `::after` covers this element and
             paints above it, so the pointer that hovers the clock enters the ANCHOR — hit-testing
             follows paint order — and a `pointerenter` on this span never fires. Lifting it above
             the overlay would fix the tooltip by making the row's trailing edge the one place a
             click does not open the track, which is the worse trade.
             It costs a sighted reader nothing: the day is on the section heading directly above.
             The `sr-only` line is what carries it for a screen reader, which may read this row
             with no heading anywhere near it. -->
        <span class="history-row__when">
            <icon name="recent" :size="1" />
            <span aria-hidden="true">{{ time }}</span>
            <span class="sr-only">{{ t("history.playedAt", { date: instant }) }}</span>
        </span>
    </li>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* A ROW STAYS A ROW AT EVERY WIDTH, the same decision PlaylistTracks documents: a history is
   read down, and the order is information. It wraps rather than re-flowing into cards.
   `position: relative` is what the name's stretched `::after` resolves against. */
.history-row {
    display: flex;
    position: relative;
    align-items: center;
    flex-wrap: wrap;

    padding: map.get(s.$c-widget, "cell-padding");
    gap: map.get(s.$c-history, "gap");

    background-color: map.get(c.$c-widget, "cell-background");
    color: map.get(c.$c-widget, "surface");

    border-radius: map.get(s.$c-widget, "cell-radius");

    @media (prefers-reduced-motion: no-preference) {
        transition: background-color ti.$c-button;
    }

    /* The row lights under the pointer because the row is the target — the same promise the
       playlists listing makes, and it has to be the whole row or the glow is a lie. */
    &:hover {
        background-color: map.get(c.$c-history, "row-hover");
    }

    /* The ring goes round the ROW rather than under the words: the words are not what gets
       activated. `:focus-within`, since the focus actually lands on the anchor inside. */
    &:focus-within {
        outline: 2px solid map.get(c.$c-history, "focus");
        outline-offset: -2px;
    }
}

/* The kind pip and the name travel together, and take only the room they need: the facts are a
   run that follows the title — the reading order a playlist row has — rather than a block
   pushed to the trailing edge with the clock. Growing this instead puts a wide gap between a
   short title and its own artist, which reads as two columns that do not line up.
   `min-width: 0` with the shrink is what lets a long title ellipsise instead of shoving the
   rest of the row off the end. */
.history-row__subject {
    display: flex;
    align-items: center;

    min-width: 0;
    flex: 0 1 auto;
    gap: map.get(s.$c-history, "gap");
}

/* The same chip the shares list draws its subject kind in, read from that component's own
   tokens rather than minted here: it is the same statement about the same kind of thing, and
   two chips saying "this is an album" in different colours would read as two features. */
.history-row__kind {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-accordion, "fact-padding");
    gap: map.get(s.$c-accordion, "fact-inner-gap");

    background-color: map.get(c.$c-accordion, "fact-background");
    color: map.get(c.$c-accordion, "fact-surface");

    border-radius: map.get(s.$c-accordion, "fact-radius");

    font-size: 0.9rem;

    /* Never broken across two lines: a chip that wrapped between its glyph and its word would
       read as two facts. */
    white-space: nowrap;
}

.history-row__name {
    overflow: hidden;

    color: inherit;

    text-decoration: none;

    white-space: nowrap;

    text-overflow: ellipsis;

    /* THE WHOLE ROW, not the words. An <a> cannot wrap the row's other content, so the target
       is stretched over it instead — the same solution the playlist rows use. No underline on
       hover for the reason they give: the row is already the target and already says so. */
    &::after {
        position: absolute;
        inset: 0;

        content: "";
    }
}

/* The facts trail the name and disappear before it does — see the breakpoints below. */
.history-row__meta {
    display: none;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-history, "gap");

    @include m.mq("portrait") {
        display: flex;
    }
}

.history-row__fact {
    display: flex;
    align-items: center;

    gap: map.get(s.$c-history, "fact-gap");

    color: map.get(c.$c-history, "muted");

    font-size: 0.9rem;

    white-space: nowrap;
}

/* At the trailing edge, and last in the row on every width: it is the one fact about the
   listen rather than about the track, and a reader scanning for "when" wants it in one column.
   `margin-inline-start: auto` is what pins it there whatever the facts before it came to. */
.history-row__when {
    display: flex;
    align-items: center;

    margin-inline-start: auto;
    gap: map.get(s.$c-history, "fact-gap");

    color: map.get(c.$c-history, "muted");

    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;

    white-space: nowrap;
}
</style>
