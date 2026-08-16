<script setup lang="ts">
/******************************************************************************
 * AudiobookPage
 * One book's detail page, at /audiobooks/{id} (route `audiobooks.show`) — where a tile of
 * the books listing leads, and where a queued chapter points.
 *
 * Three blocks, the same three AlbumPage has and in the same order: the <Headline> naming
 * the book, the HeroSection that identifies it, and below them its CHAPTERS in the
 * server-driven DataTable. A reader moving between the two areas should not have to learn a
 * second layout.
 *
 * WHAT DIFFERS, and all of it follows from the data rather than from taste:
 *
 * - **The credits are LISTS.** An album has one album-artist; a book has as many authors and
 *   narrators as its chapters name — six and five on the two anthologies in this library —
 *   so both arrive as arrays and are printed as one comma-separated fact each.
 * - **Author and narrator are COLUMNS.** On an ordinary book they repeat down the page and
 *   say little; on an anthology they are what tells one story from the next, which is the
 *   case the columns exist for and the reason the author moved onto the chapter.
 * - **THE ROWS ARE NOT LINKS.** A chapter has no page of its own, and what a reader wants
 *   from a chapter row is to hear it — so each row carries a play button instead, and the
 *   name cell is plain text rather than an <A> to nowhere.
 * - **No genre, and no add-to-playlist for the BOOK.** The tracks CHECK forbids an audiobook a
 *   genre, and `PlaylistAdditions` resolves a subject's tracks music-only on purpose — so there
 *   is no id that names this book's tracks to a playlist. Ticked CHAPTERS are a different
 *   matter and the table's bulk actions do offer it: those travel as track ids, the one shape
 *   that can carry a chapter, and a playlist is allowed to hold one.
 *
 * PRESSING A CHAPTER QUEUES THE WHOLE BOOK and starts there (`playSubjectFrom`), rather than
 * playing that one chapter. Starting a single chapter would strand a listener at the end of
 * it, which for a book is the one thing a player must not do.
 *
 * The controller sends raw values (seconds, bytes, an ISO-8601 instant, plain counts) and the
 * formatting happens here against the active locale — the split every page here uses.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import Button from "Components/Form/Button.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import DownloadButton from "Components/Music/DownloadButton.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import SelectionActions from "Components/Music/SelectionActions.vue";
import ShareButton from "Components/Music/ShareButton.vue";
import ActionPanel from "Components/UI/ActionPanel.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import type { AudiobookBookmark } from "Composables/useAudiobookBookmark";
import { useAudiobookBookmark } from "Composables/useAudiobookBookmark";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useSubjectTracks } from "Composables/useSubjectTracks";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatDateTime, formatDecimals, formatFileSize, formatPosition } from "Utils/formatting";

/** One book as AudiobookController shaped it — every value raw. */
export interface AudiobookDetail {
    id: string;
    name: string;
    /** Every author its chapters name, sorted. One entry for an ordinary book, six for an anthology. */
    authors: string[];
    /** Every narrator its chapters name, sorted. Same shape and the same reason. */
    narrators: string[];
    /** Release year, or null for an untagged rip. */
    year: number | null;
    /** How many chapters the book has. */
    chapters: number;
    /** Distinct discs, floored to 1 — a rip with no disc tags is still one disc. */
    discs: number;
    /** Total playing time in SECONDS, or null when no chapter carried a duration. */
    duration: number | null;
    /** Total size in BYTES, or null when no chapter carried one. */
    size: number | null;
    /** ISO-8601 instant of the newest chapter file, standing in for "last changed". */
    modifiedAt: string | null;
    /** The hero's <img> source, or null when the book has no art at all. */
    coverUrl: string | null;
    /** Where the download button points — the whole book as a .zip. */
    downloadUrl: string;
}

/** One chapter row, mirroring AudiobookController's `rowMapper`. */
export interface ChapterRow {
    id: string;
    /** Disc number, or null when the file carried none. */
    disc: number | null;
    /** How many discs the book has, so the cell can print "2/5". */
    discTotal: number;
    /** Chapter number within its disc, or null when untagged. */
    track: number | null;
    /** How many chapters share this row's disc, for the "3/33" denominator. */
    trackTotal: number;
    name: string;
    /** Who wrote this chapter — null on an untagged file, and then the cell is empty. */
    author: string | null;
    /** Who reads it. Null on the same condition. */
    narrator: string | null;
    /** Playing time in SECONDS; the page clocks it. */
    duration: number | null;
}

const props = defineProps<{
    /** The book itself — everything the hero draws. */
    audiobook: AudiobookDetail;
    /** Its chapters, paginated and sorted by the server. */
    table: TableResponse<ChapterRow>;
    /** Listening events on this book: the reader's own, and everybody else's. */
    plays: { own: number; others: number };
    /** Where this reader left off, or null for a book they have not started. */
    bookmark: AudiobookBookmark | null;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();

// The book's NAME rather than a key, because a name is data — the same call every detail
// page here makes for its last crumb.
setBreadcrumbs([
    { labelKey: "header.siteMenu.audiobooks", icon: "audiobook", href: "/audiobooks" },
    { label: props.audiobook.name }
]);

// The hero's play/enqueue and every row's play button share one fetch of the book: the first
// press pays for the round trip and the rest are instant.
const { busy, playSubjectFrom, enqueueSubject } = useSubjectTracks();

/*
 * Resume, and the write that keeps it current. The chapter ids come from the TABLE, which is
 * one page of the book — enough to recognise "is what is playing one of ours", since the
 * player only ever loads a chapter this page put in the queue.
 */
const { bookmark, resume, restart } = useAudiobookBookmark(
    props.audiobook.id,
    props.bookmark,
    () => props.table.rows.map(row => row.id),
    playSubjectFrom
);

/** Whether a row is the one the reader left off at — what the bookmark glyph marks. */
const isBookmarked = (row: ChapterRow): boolean => bookmark.value?.trackId === row.id;

/**
 * Press a chapter: queue the whole book and start there.
 *
 * THE BOOKMARKED ROW RESUMES AT ITS OFFSET rather than at 0:00 — `resume()` seeks to the
 * stored millisecond — which is what makes pressing the marked row mean "carry on" and
 * pressing any other mean "start this chapter". Both queue the WHOLE book, so the reader goes
 * on to the next chapter without touching anything.
 */
const playChapter = (row: ChapterRow): Promise<unknown> =>
    isBookmarked(row) ? resume() : playSubjectFrom(row.id);

/**
 * Mark the bookmarked chapter's whole ROW, not just its glyph.
 *
 * A 16px icon in a column of 50 rows is not findable, and finding it is the entire job — so
 * the row wears the same colours as the CURRENT ITEM in the header's site menu, which is the
 * app's established way of saying "this is the one you are on". Reading that component's own
 * tokens rather than restating the colours keeps the two in step if either is ever retuned.
 */
const rowClass = (row: ChapterRow): string | undefined =>
    isBookmarked(row) ? "chapter-row--bookmarked" : undefined;

/** The book's total playing time as a clock, or empty when nothing carried a duration. */
const playingTime = computed(() => formatClock(props.audiobook.duration) ?? "");

/** Its total size, humanised — null stays empty rather than printing "0 B". */
const totalSize = computed(() =>
    props.audiobook.size === null ? "" : formatFileSize(props.audiobook.size, locale.value)
);

/** When the newest chapter file last changed, in the reader's locale. */
const modified = computed(() => formatDateTime(props.audiobook.modifiedAt, locale.value) ?? "");

/**
 * The authors as one fact, and the narrators as another.
 *
 * A comma-separated list rather than a fact each: an anthology names six authors, and six
 * tiles of the same label would push everything that describes the BOOK off the first
 * screen. The comma is safe to compose client-side where a sentence would not be — a list of
 * names reads the same in both catalogues.
 */
const authorList = computed(() => props.audiobook.authors.join(", "));
const narratorList = computed(() => props.audiobook.narrators.join(", "));

/**
 * The chapter columns, as a computed so the labels re-evaluate on a locale switch.
 *
 * CD and Track are `align: "right"` like the album's, being numbers; the two credit columns
 * are text. Playtime trails, which is where every listing in this app puts a duration.
 */
const columns = computed<ColumnDef<ChapterRow>[]>(() => [
    { key: "disc", label: t("music.columns.discs"), sortable: true, align: "right" },
    { key: "track", label: t("audiobooks.columns.chapter"), sortable: true, align: "right" },
    { key: "name", label: t("music.columns.title"), sortable: true, cardPrimary: true },
    { key: "author", label: t("audiobooks.columns.author"), sortable: true },
    { key: "narrator", label: t("audiobooks.columns.narrator"), sortable: true },
    { key: "duration", label: t("audiobooks.columns.playtime"), sortable: true, align: "right" }
]);
</script>

<template>
    <Head :title="audiobook.name" />
    <!-- Outside the Container like every other page heading — its glowing border has to reach
         the window edge so the seam hides off-screen (see Container). -->
    <headline glow>
        <icon name="audiobook" :size="3" />
        {{ audiobook.name }}
    </headline>
    <container>
        <div class="audiobook">
            <hero-section>
                <!-- The book's own name as the alt text, not "cover of …" — a screen reader
                     already says "image". Not `decorative`: here the artwork IS the subject
                     of the page. CoverImage draws its glyph when there is no art. -->
                <template #cover>
                    <cover-image :src="audiobook.coverUrl" :title="audiobook.name" size="xlarge" />
                </template>
                <!-- The facts that belong to the book as a whole. Each is skipped when there
                     is nothing to say: an anthology chapter may credit nobody, an untagged
                     rip has no year. The counts always exist. -->
                <template #metadata>
                    <fact-pair
                        v-if="authorList"
                        icon="author"
                        :label="t(audiobook.authors.length > 1 ? 'audiobooks.columns.authors' : 'audiobooks.columns.author')"
                        :value="authorList"
                    />
                    <fact-pair
                        v-if="narratorList"
                        icon="narrator"
                        :label="t(audiobook.narrators.length > 1 ? 'audiobooks.columns.narrators' : 'audiobooks.columns.narrator')"
                        :value="narratorList"
                    />
                    <fact-pair
                        v-if="audiobook.year !== null"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(audiobook.year)"
                    />
                    <fact-pair
                        icon="track"
                        :label="t('audiobooks.columns.chapters')"
                        :value="formatDecimals(audiobook.chapters, locale)"
                    />
                    <fact-pair icon="album" :label="t('music.columns.discs')" :value="String(audiobook.discs)" />
                    <fact-pair
                        v-if="playingTime"
                        icon="duration"
                        :label="t('audiobooks.columns.playtime')"
                        :value="playingTime"
                    />
                    <fact-pair v-if="totalSize" icon="file" :label="t('music.columns.size')" :value="totalSize" />
                    <fact-pair
                        v-if="modified"
                        icon="calendar"
                        :label="t('music.columns.modifiedAt')"
                        :value="modified"
                    />
                    <!-- Last, and only when there is something to say: what has actually been
                         listened to comes after what the book IS. -->
                    <play-count-facts :plays="plays" subject="album" />
                </template>
                <!-- The tinted ActionPanel for what a reader came for, and under it the two
                     actions that take the book somewhere else: onto a disk, or to somebody
                     without an account. -->
                <template #actions>
                    <action-panel>
                        <!-- THREE VERBS, WRITTEN OUT HERE RATHER THAN `SubjectActions`, which
                             every Music hero uses. That component pairs play + enqueue, and its
                             play always starts at the beginning — beside a resume button it read
                             as two play buttons, and the plainer-looking one would silently
                             restart a book you were forty chapters into.

                             Play RESUMES, with a separate way back to the start beside it: on a
                             book part-way through, resuming is what the obvious big button
                             should do, and restarting is the rarer verb that still has to be
                             reachable. Enqueue is the same verb SubjectActions offers and wears
                             its label, since a queue is a queue in either area. -->
                        <Button variant="primary" no-halo :disabled="busy" @click="resume">
                            <icon :name="busy ? 'refresh' : 'playlist'" :size="1" :rotate="busy" />
                            <span>{{ t(bookmark ? "audiobooks.actions.resume" : "audiobooks.actions.play") }}</span>
                        </Button>
                        <!-- `default`, not primary: starting a book over is
                             the rarer of the two ways to press play, and the outline look says
                             so beside the filled one that resumes. -->
                        <Button v-if="bookmark" no-halo :disabled="busy" @click="restart">
                            <icon name="first-page" :size="1" />
                            <span>{{ t("audiobooks.actions.restart") }}</span>
                        </Button>
                        <Button variant="primary" no-halo :disabled="busy" @click="enqueueSubject">
                            <icon :name="busy ? 'refresh' : 'playlist_add'" :size="1" :rotate="busy" />
                            <span>{{ t("music.subjectActions.enqueue") }}</span>
                        </Button>
                    </action-panel>
                    <download-button :href="audiobook.downloadUrl" subject="audiobook" />
                    <share-button subject="audiobook" :subject-id="audiobook.id" />
                </template>
            </hero-section>

            <!-- No actions column, because there is no per-row control to put in one: the ROW
                 is the control (`row-clickable`), and pressing it plays the book from that
                 chapter on rather than opening a page about it. -->
            <data-table
                :columns="columns"
                :response="table"
                :base-url="`/audiobooks/${audiobook.id}`"
                :has-actions="false"
                :row-class="rowClass"
                row-clickable
                selectable
                @row-click="playChapter"
            >
                <!-- Ticked chapters mean JUST THOSE CHAPTERS, which is deliberately not what a
                     row press means one line below (that plays the whole book from there). A
                     checkbox is an explicit act of picking, so it can carry the narrower reading
                     the row itself must not. -->
                <template #toolbar-actions>
                    <selection-actions subject="song" />
                </template>
                <!-- THE TITLE IS THE CONTROL, and that is an accessibility decision rather
                     than a style one: the whole row is pressable for a pointer, but a row is
                     not focusable and announces nothing, so without a real control here the
                     chapters would be unreachable by keyboard entirely. The music tables make
                     the title a <Link> for exactly this reason; a chapter has no page to link
                     to, so it is a <button> that does what the row does.

                     The bookmark glyph rides in the same cell — a column of its own would be
                     empty on all 672 other rows of a big book. -->
                <template #cell-name="{ row }">
                    <button
                        type="button"
                        class="chapter-name"
                        :disabled="busy"
                        :aria-label="`${t('audiobooks.chapter.play')}: ${row.name}`"
                        @click="playChapter(row)"
                    >
                        <!-- `additional-classes`, which is Icon's own API: it builds its class
                             list from its props, so a raw `class` is not the way to reach it. -->
                        <icon
                            v-if="isBookmarked(row)"
                            name="bookmark"
                            :size="1"
                            :additional-classes="['chapter-name__mark']"
                            :aria-label="t('audiobooks.chapter.bookmarked')"
                        />
                        {{ row.name }}
                    </button>
                </template>
                <template #cell-disc="{ row }">
                    {{ formatPosition(row.disc, row.discTotal) ?? "" }}
                </template>
                <template #cell-track="{ row }">
                    {{ formatPosition(row.track, row.trackTotal) ?? "" }}
                </template>
                <template #cell-duration="{ row }">
                    {{ formatClock(row.duration) ?? "" }}
                </template>
                <template #empty>{{ t("components.datatable.no_results") }}</template>
            </data-table>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The page's two blocks, spaced by the card gap — a page reads the token of the component
   that already defines it rather than minting one of its own (CLAUDE.md → Design tokens). */
.audiobook {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* THE CHAPTER YOU LEFT OFF AT, wearing the site menu's CURRENT-ITEM colours: dark green with
   yellow ink in dark mode, and the inverse in light. Measured against the header's own pill —
   both `rgb(22, 50, 27)` on `rgb(255, 230, 77)` — because
   reading `$c-site-menu-links` rather than restating the values is what keeps them in step if
   either is ever retuned. A page reads the token of the component that already defines a
   value; it does not mint a duplicate (CLAUDE.md → tokens).

   BOTH HALVES GO ON THE CELLS, and the selector is counted rather than guessed. DataTableBody
   stripes with `.dt-body tr:nth-child(odd) td` — three class-level units and two elements once
   Vue's scope attribute joins in — and its own comment records losing a tie to exactly that
   rule. A plain `:deep(.chapter-row--bookmarked td)` carries two and one, so the stripe won:
   the fill showed through the semi-transparent stripe diluted, which read as a washed-out
   green (the owner's report), and the ink was overridden outright. Naming `.dt-body` and
   pinning `:nth-child(n)` — which matches every row and exists here only for its weight —
   makes it four and two, a clear win rather than a tie settled by source order.

   The row itself keeps the fill too, so the halo a hovered row paints outside its cells sits
   on the right colour. */
:deep(.dt-body tr.chapter-row--bookmarked) {
    background-color: map.get(c.$c-site-menu-links, "active-background");
}

:deep(.dt-body tr.chapter-row--bookmarked:nth-child(n) td) {
    background-color: map.get(c.$c-site-menu-links, "active-background");
    color: map.get(c.$c-site-menu-links, "active-surface");
}

/* The glyph rides the row's ink rather than carrying a colour of its own. */
.chapter-name__mark {
    color: inherit;
}

/* One flex item around the glyph and the title, so a long chapter name wraps as ONE block
   under its own first line rather than the mark being pushed onto a line of its own — the
   trap a bare text node beside an icon falls into in a wrapping row.

   IT IS A <button> WEARING THE CELL'S OWN TEXT: stripped of every button default, because the
   affordance a reader is meant to see is the ROW lighting up under the pointer, not a control
   in one cell. What the element buys is the half a row cannot give — focus, Enter and Space,
   and a name a screen reader can announce. The focus ring is left to the browser rather than
   removed, since it is the only thing that shows a keyboard user where they are. */
.chapter-name {
    display: flex;
    align-items: center;

    width: 100%;
    min-width: 0;

    padding: 0;
    border: 0;
    gap: 0.4ch;

    background: none;
    color: inherit;

    font: inherit;
    text-align: start;

    cursor: pointer;

    &:disabled {
        cursor: default;
    }
}

.chapter-name__mark {
    flex: none;
}
</style>
